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
 *
 * Updating the code later:
 *   Deploy > Manage deployments > pencil icon on the deployment above >
 *   Version: "New version" > Deploy. That keeps the same /exec URL and picks
 *   up the new code. Do NOT use "New deployment" for updates — that creates a
 *   second URL while the first deployment keeps serving whatever code existed
 *   when it was created, which is why "only the first version worked."
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
  var updateId = update.update_id ? String(update.update_id) : null;

  // Claim the update before anything else — the acknowledgement included.
  // Telegram redelivers an update it does not get a prompt 200 back for, and
  // the app routinely takes longer than that (file download, DB writes), so
  // redeliveries of an update already in flight are the normal case, not an
  // edge case. Acknowledging first meant every one of them earned its own 👍
  // and its own hourglass reply, which is the bot repeating itself at the
  // sender while the first copy was still being worked on.
  if (updateId && !claimUpdate(updateId)) {
    return jsonOutput(JSON.stringify({ ok: true, duplicate: true }));
  }

  // Still ahead of the forward, so the sender gets receipt feedback right away
  // rather than after the app has finished with the resume.
  acknowledgeUpdate(update);

  var response = UrlFetchApp.fetch(API_BASE + '/telegram/webhook', {
    method: 'post',
    contentType: 'application/json',
    headers: apiHeaders(),
    payload: JSON.stringify(update),
    muteHttpExceptions: true
  });

  // Let a failed forward throw, which makes this whole doPost fail loudly
  // instead of returning 200 — that is what makes Telegram retry the update
  // once the app is back, instead of the update being silently dropped. The
  // claim has to go back with it, or the retry is answered "duplicate" and the
  // update is lost for good, having never been handled once.
  if (response.getResponseCode() >= 300) {
    releaseUpdate(updateId);
    throw new Error('Forward to app failed: ' + response.getResponseCode() + ' ' + response.getContentText());
  }

  return jsonOutput(response.getContentText());
}

/**
 * True if this is the first delivery of update_id. The script lock makes the
 * check-and-mark one indivisible step, so two redeliveries landing at the same
 * moment — separate Apps Script executions — cannot both come away believing
 * they are the first.
 */
function claimUpdate(updateId) {
  var lock = LockService.getScriptLock();
  try {
    lock.waitLock(15000);
  } catch (err) {
    // Never long-held, so this means something is badly wrong. Failing is the
    // safe answer: Telegram retries, where claiming or dropping would risk a
    // second acknowledgement or a lost message.
    throw new Error('Could not take the update lock: ' + err.message);
  }

  try {
    var cache = CacheService.getScriptCache();
    var key = 'update:' + updateId;
    if (cache.get(key)) return false;
    cache.put(key, '1', 21600);
    return true;
  } finally {
    lock.releaseLock();
  }
}

/** Hands a claim back so Telegram's next redelivery gets a fresh attempt. */
function releaseUpdate(updateId) {
  if (!updateId) return;
  CacheService.getScriptCache().remove('update:' + updateId);
}

/**
 * Tells the sender their message landed, two ways: a 👍 reaction, and an
 * hourglass reply. The reaction is easy to miss and Telegram declines it
 * outright in some chats, so the reply is the receipt that always shows.
 * Both are best effort — neither failing may stop the update reaching the app.
 */
function acknowledgeUpdate(update) {
  var message = update.message || update.edited_message;
  if (!message || !message.chat || !message.message_id) return;

  var chatId = String(message.chat.id);

  bestEffort('setMessageReaction', {
    chat_id: chatId,
    message_id: message.message_id,
    reaction: [{ type: 'emoji', emoji: '👍' }]
  });

  bestEffort('sendMessage', {
    chat_id: chatId,
    text: '⏳ Got it — working on this.',
    reply_parameters: { message_id: message.message_id }
  });
}

/**
 * A Telegram call that must never take the caller down with it. Retries once,
 * because transient 5xx and per-chat rate limits are common enough that a
 * single attempt visibly drops receipts.
 */
function bestEffort(method, params) {
  for (var attempt = 1; attempt <= 2; attempt++) {
    try {
      return telegram(method, params);
    } catch (err) {
      if (attempt === 2) console.log(method + ' failed: ' + err.message);
      else Utilities.sleep(400);
    }
  }
  return null;
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
  var result = telegram('setWebhook', { url: url, allowed_updates: ['message', 'edited_message'] });
  console.log('webhook set to ' + url + ': ' + JSON.stringify(result));
  console.log('Set gateway_url in the admin Settings screen to: ' + url + '?secret=' + prop('GATEWAY_SECRET'));
}

function deleteWebhook() {
  console.log(JSON.stringify(telegram('deleteWebhook', {})));
}

/**
 * Start clean: throw away every update Telegram is still holding, then point
 * the webhook at this deployment's URL. Use it when the bot is chewing through
 * a backlog of old messages, or after a URL change left updates queued up.
 */
function resetWebhook() {
  telegram('deleteWebhook', { drop_pending_updates: true });

  var url = ScriptApp.getService().getUrl();
  telegram('setWebhook', {
    url: url,
    allowed_updates: ['message', 'edited_message'],
    drop_pending_updates: true
  });

  console.log('webhook reset to ' + url);
  console.log(JSON.stringify(telegram('getWebhookInfo', {})));
}

/** Prints the live webhook URL and how many updates are queued behind it. */
function webhookInfo() {
  console.log(JSON.stringify(telegram('getWebhookInfo', {}), null, 2));
}

function testApiAuth() {
  var response = UrlFetchApp.fetch(API_BASE + '/auth/me', {
    headers: apiHeaders(),
    muteHttpExceptions: true
  });
  console.log(response.getResponseCode() + ' ' + response.getContentText());
}
