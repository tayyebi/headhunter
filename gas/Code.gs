/**
 * Telegram gateway for the headhunter API.
 *
 * This is the ONLY component that knows Telegram exists. To the REST API it is
 * just a client holding a bearer token for a machine account, and the API
 * addresses candidates by an opaque `external_ref` that we define here as
 * "telegram:<chat id>".
 *
 * doPost handles two kinds of request:
 *   1. Telegram webhook updates  (candidate -> us)
 *   2. Delivery pushes from our API (us -> candidate), authenticated with a shared secret
 *
 * Setup:
 *   1. Deploy > New deployment > Web app, execute as me, access "Anyone".
 *   2. Project Settings > Script Properties, add:
 *        BOT_TOKEN        the Telegram bot token
 *        API_TOKEN        the gateway token (printed on first boot, or reissued
 *                         from the admin app's Users screen)
 *        GATEWAY_SECRET   any long random string
 *        ADMIN_ID         optional. A chat id (a normal user is fine) that gets
 *                         a PV message whenever doPost throws. Message the bot
 *                         privately and send /whoami to read off your chat id.
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

function optionalProp(name) {
  return PropertiesService.getScriptProperties().getProperty(name) || null;
}

// Best-effort PV alert to ADMIN_ID. Swallows its own errors so a broken/unset
// ADMIN_ID or a down Telegram API never turns into a second unhandled error.
function notifyAdmin(message) {
  var id = optionalProp('ADMIN_ID');
  if (!id) return;
  try {
    telegram('sendMessage', { chat_id: id, text: message });
  } catch (err) {
    console.error('Failed to notify admin: ' + err);
  }
}

function apiHeaders() {
  return { Authorization: 'Bearer ' + prop('API_TOKEN') };
}

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

// --------------------------------------------------------------------------
// Entry point
// --------------------------------------------------------------------------

function doPost(e) {
  var ok = ContentService.createTextOutput(JSON.stringify({ ok: true }))
    .setMimeType(ContentService.MimeType.JSON);

  var payload;
  try {
    payload = JSON.parse(e.postData.contents);
  } catch (err) {
    return ok;
  }

  try {
    // A delivery push carries our own secret; a Telegram update never does.
    var secret = (e.parameter && e.parameter.secret) || null;
    if (payload.external_ref && payload.kind) {
      handleDelivery(payload, secret);
    } else if (!isDuplicateUpdate(payload.update_id)) {
      handleTelegramUpdate(payload);
    }
  } catch (err) {
    console.error(err);
    var context = (payload.external_ref && payload.kind)
      ? 'delivery ' + (payload.delivery_id || '(no id)') + ' to ' + payload.external_ref
      : 'update ' + (payload.update_id || '(no id)');
    notifyAdmin('headhunter gateway error on ' + context + ':\n' + (err && err.stack ? err.stack : err));
    // Always 200 to Telegram, otherwise it retries the same update forever.
  }

  return ok;
}

// Telegram retries a webhook update if it does not get a prompt 200 back.
// handleTelegramUpdate does several sequential network calls (sendMessage,
// getFile, the file download, the /intake POST) before doPost can respond, so
// a slow run causes Telegram to redeliver the same update while the first run
// is still going, and the candidate sees the same reply more than once.
function isDuplicateUpdate(updateId) {
  if (!updateId) return false;
  var cache = CacheService.getScriptCache();
  var key = 'update:' + updateId;
  if (cache.get(key)) return true;
  cache.put(key, '1', 21600);
  return false;
}

// --------------------------------------------------------------------------
// Candidate -> us
// --------------------------------------------------------------------------

function handleTelegramUpdate(update) {
  var message = update.message || update.edited_message;
  if (!message) return;

  var chatId = message.chat.id;
  var text = message.text || '';

  if (text.indexOf('/start') === 0) {
    telegram('sendMessage', {
      chat_id: chatId,
      text: 'سلام! رزومه‌تان را به صورت فایل PDF همین‌جا بفرستید تا بررسی و ویرایش شود.\n\n' +
            'Send your resume here as a PDF file and we will polish it for you.'
    });
    return;
  }

  if (text.indexOf('/whoami') === 0) {
    telegram('sendMessage', {
      chat_id: chatId,
      message_thread_id: message.message_thread_id,
      text: whoamiText(message)
    });
    return;
  }

  var file = message.document || largestPhoto(message);
  if (!file) {
    telegram('sendMessage', {
      chat_id: chatId,
      text: 'لطفاً رزومه را به صورت فایل PDF ارسال کنید.\nPlease send your resume as a PDF file.'
    });
    return;
  }

  var blob = downloadTelegramFile(file.file_id, file.file_name || 'resume.pdf');

  var response = UrlFetchApp.fetch(API_BASE + '/intake', {
    method: 'post',
    headers: apiHeaders(),
    payload: {
      external_ref: 'telegram:' + chatId,
      display_name: displayName(message.from),
      file: blob
    },
    muteHttpExceptions: true
  });

  if (response.getResponseCode() >= 300) {
    console.error('intake failed: ' + response.getResponseCode() + ' ' + response.getContentText());
    telegram('sendMessage', {
      chat_id: chatId,
      text: 'دریافت فایل با خطا مواجه شد. لطفاً دوباره تلاش کنید.\nUpload failed, please try again.'
    });
    return;
  }

  telegram('sendMessage', {
    chat_id: chatId,
    text: 'رزومه شما دریافت شد. پس از بررسی، نسخه ویرایش‌شده برایتان ارسال می‌شود.\n\n' +
          'Got your resume. We will send the polished version back here once it is ready.'
  });
}

function largestPhoto(message) {
  if (!message.photo || !message.photo.length) return null;
  var photo = message.photo[message.photo.length - 1];
  return { file_id: photo.file_id, file_name: 'resume.jpg' };
}

function displayName(from) {
  if (!from) return '';
  return [from.first_name, from.last_name].filter(Boolean).join(' ') ||
         (from.username ? '@' + from.username : '');
}

// Dumps the identifiers of whoever/wherever sent /whoami: chat id (needed for
// ADMIN_ID or external_ref lookups), the forum topic id if this is a topic in
// a supergroup, and the sender's own id/username.
function whoamiText(message) {
  var chat = message.chat;
  var from = message.from || {};
  var lines = [
    'chat_id: ' + chat.id,
    'chat_type: ' + chat.type
  ];
  if (chat.title) lines.push('chat_title: ' + chat.title);
  if (message.message_thread_id) lines.push('topic_id: ' + message.message_thread_id);
  lines.push('user_id: ' + (from.id !== undefined ? from.id : ''));
  if (from.username) lines.push('username: @' + from.username);
  var name = displayName(from);
  if (name) lines.push('name: ' + name);
  if (from.language_code) lines.push('language_code: ' + from.language_code);
  return lines.join('\n');
}

function downloadTelegramFile(fileId, fileName) {
  var info = telegram('getFile', { file_id: fileId });
  var url = 'https://api.telegram.org/file/bot' + prop('BOT_TOKEN') + '/' + info.file_path;
  var blob = UrlFetchApp.fetch(url, { muteHttpExceptions: true }).getBlob();
  return blob.setName(fileName);
}

// --------------------------------------------------------------------------
// Us -> candidate
// --------------------------------------------------------------------------

function handleDelivery(payload, secretFromQuery) {
  // The API sends the secret as a header, which Apps Script does not expose to
  // scripts, so it is also accepted as a ?secret= query parameter. Set the
  // gateway_url in Settings to <web app url>?secret=<GATEWAY_SECRET>.
  var expected = prop('GATEWAY_SECRET');
  if (secretFromQuery !== expected) {
    throw new Error('Rejected delivery: bad or missing gateway secret.');
  }

  // The worker gives up on a slow push and retries it. Without this check a
  // candidate would receive the same document twice whenever sending succeeded
  // but the response did not get back in time.
  var cache = CacheService.getScriptCache();
  var seenKey = payload.idempotency_key ? 'sent:' + payload.idempotency_key : null;
  if (seenKey && cache.get(seenKey)) {
    console.log('skipping duplicate delivery ' + payload.delivery_id);
    return;
  }

  var chatId = String(payload.external_ref).replace(/^telegram:/, '');

  if (payload.kind === 'document' && payload.file_url) {
    var response = UrlFetchApp.fetch(payload.file_url, {
      headers: apiHeaders(),
      muteHttpExceptions: true
    });
    if (response.getResponseCode() >= 300) {
      throw new Error('Could not fetch attachment: ' + response.getResponseCode());
    }

    var blob = response.getBlob().setName(payload.file_name || 'resume.pdf');
    var form = {
      chat_id: chatId,
      document: blob
    };
    if (payload.text) form.caption = payload.text.substring(0, 1024);

    var sent = UrlFetchApp.fetch(
      'https://api.telegram.org/bot' + prop('BOT_TOKEN') + '/sendDocument',
      { method: 'post', payload: form, muteHttpExceptions: true }
    );
    if (sent.getResponseCode() >= 300) {
      throw new Error('sendDocument failed: ' + sent.getContentText());
    }
    if (seenKey) cache.put(seenKey, '1', 21600);
    return;
  }

  telegram('sendMessage', { chat_id: chatId, text: payload.text || '' });
  if (seenKey) cache.put(seenKey, '1', 21600);
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
