/**
 * Telegram relay for the headhunter API.
 *
 * The server behind API_BASE cannot reach api.telegram.org directly, but
 * Google's servers can, so this script is only a network bridge between the
 * two. It has no idea what a command is, what a resume is, or what to say to
 * anyone — it just carries bytes across the gap. All of that lives in the
 * app now.
 *
 * doPost handles two shapes of request, told apart only by the ?secret= query
 * parameter (Apps Script cannot read request headers, so a shared secret has
 * to travel as a query parameter):
 *
 *   1. No secret            -> a Telegram webhook update. Forward the raw
 *                               body to the app; return whatever it returns.
 *   2. secret === GATEWAY_SECRET -> a relay request from the app: "call this
 *                               Telegram Bot API method with these params."
 *
 * Setup:
 *   1. Deploy > New deployment > Web app, execute as me, access "Anyone".
 *   2. Project Settings > Script Properties, add:
 *        BOT_TOKEN        the Telegram bot token
 *        API_TOKEN        the gateway token (printed on first boot, or reissued
 *                         from the admin app's Users screen)
 *        GATEWAY_SECRET   any long random string
 *   3. Put the same web app URL and GATEWAY_SECRET into the admin app's Settings screen.
 *   4. Run setWebhook() once from the editor.
 */

/** Where the API lives. Change this one line if the domain ever moves. */
var API_BASE = 'https://api.hunty.ir';

function prop(name) {
  var value = PropertiesService.getScriptProperties().getProperty(name);
  if (!value) throw new Error('Missing script property: ' + name);
  return value;
}

function apiHeaders() {
  return { Authorization: 'Bearer ' + prop('API_TOKEN') };
}

/** Calls a Telegram Bot API method and returns its `result`. Throws on failure. */
function telegram(method, payload) {
  var response = UrlFetchApp.fetch('https://api.telegram.org/bot' + prop('BOT_TOKEN') + '/' + method, {
    method: 'post',
    contentType: 'application/json',
    payload: JSON.stringify(payload),
    muteHttpExceptions: true
  });
  var body = JSON.parse(response.getContentText());
  if (!body.ok) throw new Error('Telegram ' + method + ' failed: ' + response.getContentText());
  return body.result;
}

/** A replay-guard against redelivery, keyed by whatever the caller considers unique. */
function isDuplicate(key) {
  var cache = CacheService.getScriptCache();
  if (cache.get(key)) return true;
  cache.put(key, '1', 21600);
  return false;
}

function jsonOutput(text) {
  return ContentService.createTextOutput(text).setMimeType(ContentService.MimeType.JSON);
}

// --------------------------------------------------------------------------
// Entry point
// --------------------------------------------------------------------------

function doPost(e) {
  var payload;
  try {
    payload = JSON.parse(e.postData.contents);
  } catch (err) {
    return jsonOutput(JSON.stringify({ ok: true }));
  }

  var secret = (e.parameter && e.parameter.secret) || null;
  return secret ? handleRelay(secret, payload) : handleInboundUpdate(payload);
}

// --------------------------------------------------------------------------
// Telegram -> app: forward the raw update, unopened.
// --------------------------------------------------------------------------

function handleInboundUpdate(update) {
  // React before forwarding so the sender gets immediate receipt feedback even
  // when API processing involves a file download or other slow work.
  reactToUpdate(update);

  // Telegram redelivers an update if it does not get a prompt 200 back, and
  // the app takes long enough (file download, DB writes) that this happens
  // routinely. This is a mechanical replay-guard against that, not a
  // decision about the update's content.
  if (update.update_id && isDuplicate('update:' + update.update_id)) {
    return jsonOutput(JSON.stringify({ ok: true, duplicate: true }));
  }

  var response = UrlFetchApp.fetch(API_BASE + '/telegram/webhook', {
    method: 'post',
    contentType: 'application/json',
    headers: apiHeaders(),
    payload: JSON.stringify(update),
    muteHttpExceptions: true
  });

  // Let a failed forward throw, which makes this whole doPost fail loudly
  // instead of returning 200 — that is what makes Telegram retry the update
  // once the app is back, instead of the update being silently dropped.
  if (response.getResponseCode() >= 300) {
    throw new Error('Forward to app failed: ' + response.getResponseCode() + ' ' + response.getContentText());
  }

  return jsonOutput(response.getContentText());
}

function reactToUpdate(update) {
  var message = update.message || update.edited_message;
  if (!message || !message.chat || !message.message_id) return;
  try {
    telegram('setMessageReaction', {
      chat_id: String(message.chat.id),
      message_id: message.message_id,
      reaction: [{ type: 'emoji', emoji: '👍' }]
    });
  } catch (err) {
    // Receipt acknowledgement is best effort; forwarding must still happen.
    console.log('Reaction failed: ' + err.message);
  }
}

// --------------------------------------------------------------------------
// App -> Telegram: make the call the app asked for, nothing more.
//
// Body shape: { method, params, attachment_url?, attachment_param?, idempotency_key? }
// --------------------------------------------------------------------------

function handleRelay(secret, envelope) {
  if (secret !== prop('GATEWAY_SECRET')) {
    throw new Error('Rejected relay: bad gateway secret.');
  }

  var cache = CacheService.getScriptCache();
  var cacheKey = envelope.idempotency_key ? 'relay:' + envelope.idempotency_key : null;
  if (cacheKey) {
    var cached = cache.get(cacheKey);
    if (cached) return jsonOutput(cached);
  }

  // The app never gets the bot token, so fetching a Telegram file's bytes has
  // to happen here; the result is streamed straight back as the response body.
  if (envelope.method === 'downloadFile') {
    var info = telegram('getFile', { file_id: envelope.params.file_id });
    var url = 'https://api.telegram.org/file/bot' + prop('BOT_TOKEN') + '/' + info.file_path;
    var blob = UrlFetchApp.fetch(url, { muteHttpExceptions: true }).getBlob();
    if (envelope.params.file_name) blob = blob.setName(envelope.params.file_name);
    return blob;
  }

  var params = envelope.params || {};
  if (envelope.attachment_url && envelope.attachment_param) {
    var fetched = UrlFetchApp.fetch(envelope.attachment_url, { headers: apiHeaders(), muteHttpExceptions: true });
    if (fetched.getResponseCode() >= 300) {
      throw new Error('Could not fetch attachment: ' + fetched.getResponseCode());
    }
    params[envelope.attachment_param] = fetched.getBlob();
  }

  var result = telegram(envelope.method, params);
  var body = JSON.stringify({ ok: true, result: result });
  if (cacheKey) cache.put(cacheKey, body, 21600);
  return jsonOutput(body);
}

// --------------------------------------------------------------------------
// One-time helpers, run these from the editor
// --------------------------------------------------------------------------

function setWebhook() {
  var url = ScriptApp.getService().getUrl();
  var result = telegram('setWebhook', { url: url, allowed_updates: ['message'] });
  console.log('webhook set to ' + url + ': ' + JSON.stringify(result));
  console.log('Set gateway_url in the admin Settings screen to: ' + url + '?secret=' + prop('GATEWAY_SECRET'));
}

function deleteWebhook() {
  console.log(JSON.stringify(telegram('deleteWebhook', {})));
}

function testApiAuth() {
  var response = UrlFetchApp.fetch(API_BASE + '/auth/me', {
    headers: apiHeaders(),
    muteHttpExceptions: true
  });
  console.log(response.getResponseCode() + ' ' + response.getContentText());
}
