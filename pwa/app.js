'use strict';

/**
 * Headhunter admin client. No build step, no framework: edit this file on the
 * host and reload the page.
 *
 * Sign in once, keep a bearer token. The token is revocable and expires, and it
 * is not a database credential — the database is only ever reached by the API.
 */

/** Where the API lives. Change this one line if the domain ever moves. */
const API_BASE = 'https://api.hunty.ir';

const store = {
  get token() { return localStorage.getItem('hh.token') || ''; },
  get role()  { return localStorage.getItem('hh.role')  || ''; },
  get name()  { return localStorage.getItem('hh.name')  || ''; },
  save(token, user) {
    localStorage.setItem('hh.token', token);
    localStorage.setItem('hh.role', user.role);
    localStorage.setItem('hh.name', user.username);
  },
  clear() { ['hh.token', 'hh.role', 'hh.name'].forEach((k) => localStorage.removeItem(k)); }
};

function authHeader() {
  return 'Bearer ' + store.token;
}

async function api(method, path, body, anonymous) {
  const options = { method, headers: anonymous ? {} : { Authorization: authHeader() } };

  if (body instanceof FormData) {
    options.body = body;
  } else if (body !== undefined) {
    options.headers['Content-Type'] = 'application/json';
    options.body = JSON.stringify(body);
  }

  let response;
  try {
    response = await fetch(API_BASE + path, options);
  } catch (err) {
    throw new Error('Cannot reach the API at ' + API_BASE + '.');
  }

  if (response.status === 204) return {};

  const text = await response.text();
  let data = {};
  try { data = text ? JSON.parse(text) : {}; } catch (err) { data = { error: text.slice(0, 300) }; }

  if (!response.ok) {
    const error = new Error(data.error || ('HTTP ' + response.status));
    error.status = response.status;
    throw error;
  }
  return data;
}

/**
 * File endpoints need the Authorization header, and browsers refuse credentials
 * embedded in an iframe or link URL. So fetch the bytes and hand out a blob URL.
 */
async function fileBlobUrl(path) {
  const response = await fetch(API_BASE + path, { headers: { Authorization: authHeader() } });
  if (!response.ok) throw new Error('Could not load the file (HTTP ' + response.status + ').');
  return URL.createObjectURL(await response.blob());
}

async function openFile(path) {
  try {
    const url = await fileBlobUrl(path);
    window.open(url, '_blank');
    setTimeout(() => URL.revokeObjectURL(url), 120000);
  } catch (err) {
    toast(err.message, true);
  }
}

/** Load a file endpoint into an already-placed iframe. */
function loadPreview(frame, path) {
  fileBlobUrl(path)
    .then((url) => { frame.src = url; })
    .catch((err) => toast(err.message, true));
  return frame;
}

// ---------------------------------------------------------------------------
// Tiny DOM helpers
// ---------------------------------------------------------------------------

function el(tag, attrs, children) {
  const node = document.createElement(tag);
  for (const [key, value] of Object.entries(attrs || {})) {
    if (value === null || value === undefined || value === false) continue;
    if (key === 'class') node.className = value;
    else if (key === 'html') node.innerHTML = value;
    else if (key === 'text') node.textContent = value;
    else if (key.startsWith('on')) node.addEventListener(key.slice(2), value);
    else node.setAttribute(key, value === true ? '' : value);
  }
  for (const child of [].concat(children || [])) {
    if (child === null || child === undefined || child === false) continue;
    node.append(child.nodeType ? child : document.createTextNode(String(child)));
  }
  return node;
}

function field(label, name, value, type) {
  const input = type === 'textarea'
    ? el('textarea', { name, text: value || '' })
    : el('input', { name, type: type || 'text', value: value || '' });
  return el('div', {}, [el('label', { text: label }), input]);
}

function toast(message, isError) {
  const node = document.getElementById('toast');
  node.textContent = message;
  node.className = 'show' + (isError ? ' error' : '');
  clearTimeout(toast.timer);
  toast.timer = setTimeout(() => { node.className = ''; }, isError ? 6000 : 2600);
}

function when(value) {
  if (!value) return '';
  const date = new Date(value);
  return isNaN(date) ? value : date.toLocaleString();
}

const app = () => document.getElementById('app');
function show(...nodes) { app().replaceChildren(...nodes); }
function busy(text) { show(el('div', { class: 'empty', text: text || 'Loading…' })); }

// ---------------------------------------------------------------------------
// Login
// ---------------------------------------------------------------------------

function viewLogin() {
  document.getElementById('topbar').hidden = true;

  const form = el('form', {
    class: 'card stack',
    onsubmit: async (event) => {
      event.preventDefault();
      const data = new FormData(form);
      const button = form.querySelector('button');
      button.disabled = true;
      try {
        const result = await api('POST', '/auth/login', {
          username: String(data.get('user')).trim(),
          password: String(data.get('pass'))
        }, true);
        store.save(result.token, result.user);
        toast('Signed in as ' + result.user.username);
        location.hash = '#/inbox';
        route();
      } catch (err) {
        store.clear();
        toast(err.message, true);
      } finally {
        button.disabled = false;
      }
    }
  }, [
    el('h2', { text: 'Sign in' }),
    el('p', { class: 'muted', text: 'Sign in with your headhunter account.' }),
    el('p', { class: 'muted mono', text: API_BASE }),
    field('Username', 'user', store.name),
    field('Password', 'pass', '', 'password'),
    el('button', { class: 'primary', type: 'submit', text: 'Sign in' })
  ]);

  show(el('h1', { text: 'Headhunter' }), form);
}

// ---------------------------------------------------------------------------
// Inbox
// ---------------------------------------------------------------------------

async function viewInbox() {
  chrome('Inbox', false);
  busy();

  const search = el('input', {
    type: 'search', placeholder: 'Search name, phone or reference…', value: viewInbox.query || ''
  });
  let timer;
  search.addEventListener('input', () => {
    clearTimeout(timer);
    timer = setTimeout(() => { viewInbox.query = search.value; viewInbox(); }, 250);
  });

  let data;
  try {
    data = await api('GET', '/candidates' + (viewInbox.query ? '?q=' + encodeURIComponent(viewInbox.query) : ''));
  } catch (err) {
    return showError(err);
  }

  const list = data.candidates.map((c) => el('a', { class: 'item', href: '#/candidate/' + c.id }, [
    el('div', { class: 'row between' }, [
      el('strong', { text: c.display_name || '(no name)' }),
      Number(c.review_count) > 0
        ? el('span', { class: 'pill needs_review', text: c.review_count + ' to review' })
        : el('span', { class: 'pill ' + c.status, text: c.status })
    ]),
    el('div', { class: 'muted' }, [
      c.resume_count + ' resume' + (Number(c.resume_count) === 1 ? '' : 's'),
      c.last_resume_at ? ' · last ' + when(c.last_resume_at) : '',
      c.external_ref ? ' · ' + c.external_ref : ''
    ])
  ]));

  show(
    el('div', { class: 'card' }, [search]),
    list.length ? el('div', {}, list) : el('div', { class: 'empty', text: 'No candidates yet.' })
  );
}

// ---------------------------------------------------------------------------
// Candidate
// ---------------------------------------------------------------------------

async function viewCandidate(id) {
  chrome('Candidate', true);
  busy();

  let data;
  try {
    data = await api('GET', '/candidates/' + id);
  } catch (err) {
    return showError(err);
  }

  const c = data.candidate;
  chrome(c.display_name || 'Candidate', true);

  const profile = el('form', {
    class: 'card stack',
    onsubmit: async (event) => {
      event.preventDefault();
      const form = new FormData(event.target);
      try {
        await api('PATCH', '/candidates/' + id, {
          display_name: form.get('display_name'),
          phone: form.get('phone'),
          note: form.get('note'),
          status: form.get('status')
        });
        toast('Saved');
      } catch (err) { toast(err.message, true); }
    }
  }, [
    el('h2', { text: 'Profile' }),
    el('div', { class: 'grid2' }, [
      field('Name', 'display_name', c.display_name),
      field('Phone', 'phone', c.phone)
    ]),
    el('div', {}, [
      el('label', { text: 'Status' }),
      el('select', { name: 'status' }, ['new', 'active', 'archived'].map(
        (s) => el('option', { value: s, selected: s === c.status, text: s })
      ))
    ]),
    field('Private note', 'note', c.note, 'textarea'),
    el('div', { class: 'row' }, [
      el('button', { class: 'primary', type: 'submit', text: 'Save' }),
      c.external_ref ? el('span', { class: 'muted mono', text: c.external_ref }) : null
    ])
  ]);

  const resumes = el('div', { class: 'card' }, [
    el('h2', { text: 'Resumes' }),
    ...(data.resumes.length ? data.resumes.map((r) => el('div', { class: 'entry' }, [
      el('div', { class: 'row between' }, [
        el('div', { class: 'grow' }, [
          el('div', { text: r.orig_filename || ('resume ' + r.id) }),
          el('div', { class: 'muted', text: when(r.created_at) + ' · ' + r.source + ' · ' + Math.round(r.size_bytes / 1024) + ' KB' })
        ])
      ]),
      el('div', { class: 'row' }, [
        el('button', { class: 'small', onclick: () => openFile('/resumes/' + r.id + '/file'), text: 'Open original' }),
        el('button', {
          class: 'small primary',
          onclick: async () => {
            try {
              const created = await api('POST', '/resumes/' + r.id + '/runs');
              toast('Queued run #' + created.run.id);
              viewCandidate(id);
            } catch (err) { toast(err.message, true); }
          },
          text: 'Polish'
        })
      ])
    ])) : [el('p', { class: 'muted', text: 'Nothing uploaded yet.' })]),
    uploadForm(id)
  ]);

  const runs = el('div', { class: 'card' }, [
    el('h2', { text: 'Runs' }),
    ...(data.runs.length ? data.runs.map((r) => el('a', { class: 'item', href: '#/run/' + r.id }, [
      el('div', { class: 'row between' }, [
        el('strong', { text: 'Run #' + r.id }),
        el('span', { class: 'pill ' + r.status, text: r.status.replace('_', ' ') })
      ]),
      el('div', { class: 'muted', text: when(r.created_at) + (r.model ? ' · ' + r.model : '') }),
      r.error ? el('div', { class: 'muted', text: r.error }) : null
    ])) : [el('p', { class: 'muted', text: 'No runs yet.' })])
  ]);

  const message = el('form', {
    class: 'card stack',
    onsubmit: async (event) => {
      event.preventDefault();
      const text = new FormData(event.target).get('body');
      if (!String(text).trim()) return;
      try {
        await api('POST', '/deliveries', { candidate_id: Number(id), body: text });
        event.target.reset();
        toast('Queued for delivery');
        viewCandidate(id);
      } catch (err) { toast(err.message, true); }
    }
  }, [
    el('h2', { text: 'Send a message' }),
    field('', 'body', '', 'textarea'),
    el('button', { class: 'primary', type: 'submit', text: 'Send' })
  ]);

  const deliveries = data.deliveries.length ? el('div', { class: 'card' }, [
    el('h2', { text: 'Delivery log' }),
    ...data.deliveries.map((d) => el('div', { class: 'entry' }, [
      el('div', { class: 'row between' }, [
        el('span', { text: d.kind === 'document' ? (d.file_name || 'document') : (d.body || '').slice(0, 60) }),
        el('span', { class: 'pill ' + (d.status === 'sent' ? 'ready' : d.status === 'failed' ? 'failed' : 'queued'), text: d.status })
      ]),
      el('div', { class: 'muted', text: when(d.sent_at || d.created_at) + (d.attempts ? ' · ' + d.attempts + ' attempt(s)' : '') }),
      d.last_error ? el('div', { class: 'muted', text: d.last_error }) : null
    ]))
  ]) : null;

  show(profile, resumes, runs, message, deliveries);
}

function uploadForm(candidateId) {
  const input = el('input', { type: 'file', name: 'file', accept: '.pdf,.txt,application/pdf,text/plain' });
  return el('form', {
    class: 'stack',
    onsubmit: async (event) => {
      event.preventDefault();
      if (!input.files.length) return;
      const body = new FormData();
      body.append('file', input.files[0]);
      try {
        await api('POST', '/candidates/' + candidateId + '/resumes', body);
        toast('Uploaded');
        viewCandidate(candidateId);
      } catch (err) { toast(err.message, true); }
    }
  }, [
    el('label', { text: 'Upload a resume yourself' }),
    el('div', { class: 'row' }, [
      el('div', { class: 'grow' }, [input]),
      el('button', { type: 'submit', text: 'Upload' })
    ])
  ]);
}

// ---------------------------------------------------------------------------
// Run review
// ---------------------------------------------------------------------------

/** Repeatable sections of the resume, and the fields inside each entry. */
const SECTIONS = [
  { key: 'experience', title: 'Experience',
    fields: [['title', 'Title'], ['company', 'Company'], ['location', 'Location'], ['start', 'Start'], ['end', 'End']],
    lines: ['bullets', 'Bullets (one per line)'] },
  { key: 'education', title: 'Education',
    fields: [['degree', 'Degree'], ['institution', 'Institution'], ['start', 'Start'], ['end', 'End'], ['note', 'Note']] },
  { key: 'skills', title: 'Skills',
    fields: [['group', 'Group']], lines: ['items', 'Skills (one per line)'] },
  { key: 'projects', title: 'Projects',
    fields: [['name', 'Name'], ['description', 'Description'], ['link', 'Link']] },
  { key: 'certifications', title: 'Certifications',
    fields: [['name', 'Name'], ['issuer', 'Issuer'], ['year', 'Year']] },
  { key: 'languages', title: 'Languages',
    fields: [['name', 'Language'], ['level', 'Level']] }
];

async function viewRun(id) {
  chrome('Run #' + id, true);
  busy();

  let data;
  try {
    data = await api('GET', '/runs/' + id);
  } catch (err) {
    return showError(err);
  }

  const run = data.run;
  const content = run.edited || run.extracted;

  if (run.status === 'queued' || run.status === 'running') {
    show(el('div', { class: 'card stack' }, [
      el('h2', { text: 'Run #' + run.id }),
      el('p', { text: 'The worker is processing this resume. This page refreshes itself.' }),
      el('span', { class: 'pill ' + run.status, text: run.status })
    ]));
    clearTimeout(viewRun.timer);
    viewRun.timer = setTimeout(() => { if (location.hash === '#/run/' + id) viewRun(id); }, 4000);
    return;
  }

  if (!content) {
    return show(el('div', { class: 'card stack' }, [
      el('h2', { text: 'Run #' + run.id }),
      el('span', { class: 'pill failed', text: run.status }),
      el('p', { text: run.error || 'This run produced no content.' }),
      retryButton(run.id)
    ]));
  }

  const form = el('form', { class: 'stack', onsubmit: (event) => event.preventDefault() });

  form.append(el('div', { class: 'card stack' }, [
    el('h2', { text: 'Header' }),
    field('Full name', 'full_name', content.full_name),
    field('Headline', 'headline', content.headline),
    field('Summary', 'summary', content.summary, 'textarea'),
    el('div', { class: 'grid2' }, [
      field('Email', 'contact.email', (content.contact || {}).email),
      field('Phone', 'contact.phone', (content.contact || {}).phone)
    ]),
    field('Location', 'contact.location', (content.contact || {}).location)
  ]));

  for (const section of SECTIONS) {
    form.append(sectionCard(section, content[section.key] || []));
  }

  const actions = el('div', { class: 'card row' }, [
    el('button', {
      class: 'primary',
      onclick: () => saveAndRender(run.id, form, true),
      text: 'Save & render'
    }),
    el('button', { onclick: () => saveAndRender(run.id, form, false), text: 'Save only' }),
    run.has_output ? el('button', {
      onclick: async () => {
        const note = prompt('Message to send with the PDF (optional):', '');
        if (note === null) return;
        try {
          await api('POST', '/runs/' + run.id + '/deliver', { message: note });
          toast('Queued for delivery');
          viewRun(run.id);
        } catch (err) { toast(err.message, true); }
      },
      text: 'Send to candidate'
    }) : null,
    retryButton(run.id)
  ]);

  const meta = el('div', { class: 'card' }, [
    el('div', { class: 'row between' }, [
      el('strong', { text: 'Run #' + run.id }),
      el('span', { class: 'pill ' + run.status, text: run.status.replace('_', ' ') })
    ]),
    el('div', { class: 'muted', text: [run.model, run.input_mode && ('input: ' + run.input_mode), when(run.created_at)].filter(Boolean).join(' · ') }),
    run.error ? el('div', { class: 'muted', text: run.error }) : null,
    el('a', { class: 'muted', href: '#/candidate/' + run.candidate_id, text: 'Open candidate' })
  ]);

  const preview = run.has_output
    ? el('div', { class: 'card' }, [
        el('h2', { text: 'Rendered PDF' }),
        loadPreview(el('iframe', { class: 'preview' }), '/runs/' + run.id + '/output'),
        el('button', { class: 'small', onclick: () => openFile('/runs/' + run.id + '/output'), text: 'Open in a new tab' })
      ])
    : el('div', { class: 'card muted', text: 'Not rendered yet. Use “Save & render”.' });

  show(meta, actions, preview, form);
}

function retryButton(runId) {
  return el('button', {
    class: 'danger',
    onclick: async () => {
      if (!confirm('Run the AI again from the original file? Your edits on this run will be replaced.')) return;
      try {
        await api('POST', '/runs/' + runId + '/retry');
        toast('Re-queued');
        viewRun(runId);
      } catch (err) { toast(err.message, true); }
    },
    text: 'Re-run AI'
  });
}

function sectionCard(section, entries) {
  const list = el('div', {});
  const card = el('div', { class: 'card' }, [
    el('div', { class: 'row between' }, [
      el('h2', { text: section.title }),
      el('button', { class: 'small', onclick: () => list.append(entryNode(section, {}, list)), text: '+ Add' })
    ]),
    list
  ]);

  for (const entry of entries) list.append(entryNode(section, entry || {}, list));
  return card;
}

function entryNode(section, entry, list) {
  const node = el('div', { class: 'entry', 'data-section': section.key });

  node.append(el('div', { class: 'row between' }, [
    el('span', { class: 'muted', text: section.title }),
    el('button', { class: 'small danger', onclick: () => node.remove(), text: 'Remove' })
  ]));

  node.append(el('div', { class: 'grid2' }, section.fields.map(
    ([name, label]) => field(label, name, entry[name])
  )));

  if (section.lines) {
    const [name, label] = section.lines;
    const value = Array.isArray(entry[name]) ? entry[name].join('\n') : '';
    node.append(field(label, name, value, 'textarea'));
  }

  return node;
}

/** Read the form back into the resume JSON shape. */
function collect(form) {
  const value = (name) => {
    const input = form.querySelector(`[name="${name}"]`);
    return input ? input.value.trim() : '';
  };

  const data = {
    full_name: value('full_name'),
    headline: value('headline'),
    summary: value('summary'),
    contact: {
      email: value('contact.email'),
      phone: value('contact.phone'),
      location: value('contact.location'),
      links: []
    }
  };

  for (const section of SECTIONS) {
    data[section.key] = [...form.querySelectorAll(`[data-section="${section.key}"]`)].map((node) => {
      const entry = {};
      for (const [name] of section.fields) {
        entry[name] = (node.querySelector(`[name="${name}"]`) || {}).value?.trim() || '';
      }
      if (section.lines) {
        const [name] = section.lines;
        const raw = (node.querySelector(`[name="${name}"]`) || {}).value || '';
        entry[name] = raw.split('\n').map((line) => line.trim()).filter(Boolean);
      }
      return entry;
    }).filter((entry) => Object.values(entry).some((v) => (Array.isArray(v) ? v.length : v)));
  }

  return data;
}

async function saveAndRender(runId, form, render) {
  try {
    await api('PATCH', '/runs/' + runId, { edited: collect(form) });
    if (!render) { toast('Saved'); return; }
    toast('Rendering…');
    await api('POST', '/runs/' + runId + '/render');
    toast('Rendered');
    viewRun(runId);
  } catch (err) {
    toast(err.message, true);
  }
}

// ---------------------------------------------------------------------------
// Settings and account
// ---------------------------------------------------------------------------

async function viewSettings() {
  chrome('Settings', false);
  busy();

  let data;
  try {
    data = await api('GET', '/settings');
  } catch (err) {
    return showError(err);
  }

  const s = data.settings;
  const form = el('form', {
    class: 'card stack',
    onsubmit: async (event) => {
      event.preventDefault();
      const f = new FormData(event.target);
      const payload = {
        system_instruction: f.get('system_instruction'),
        ai_base_url: f.get('ai_base_url'),
        ai_model: f.get('ai_model'),
        temperature: Number(f.get('temperature')),
        gateway_url: f.get('gateway_url'),
        telegram_admin_chat_id: f.get('telegram_admin_chat_id')
      };
      if (String(f.get('ai_api_key')).trim()) payload.ai_api_key = f.get('ai_api_key');
      try {
        await api('PUT', '/settings', payload);
        toast('Saved');
        viewSettings();
      } catch (err) { toast(err.message, true); }
    }
  }, [
    el('h2', { text: 'AI instruction' }),
    el('p', { class: 'muted', text: 'This is sent as the system prompt for every polish. Each run stores a copy of what it said at the time.' }),
    field('', 'system_instruction', s.system_instruction, 'textarea'),
    el('div', { class: 'grid2' }, [
      field('AI base URL', 'ai_base_url', s.ai_base_url),
      field('Model', 'ai_model', s.ai_model)
    ]),
    el('div', { class: 'grid2' }, [
      field(s.ai_api_key_set ? 'API key (stored, ends ' + s.ai_api_key_hint + ')' : 'API key (not set)', 'ai_api_key', '', 'password'),
      field('Temperature', 'temperature', s.temperature, 'number')
    ]),
    el('h2', { text: 'Delivery gateway' }),
    el('p', { class: 'muted', text: 'The API pushes finished files here. For Apps Script, paste the complete URL logged by setWebhook(), including its secret query parameter.' }),
    field('Gateway URL', 'gateway_url', s.gateway_url),
    field('Telegram admin chat id (optional)', 'telegram_admin_chat_id', s.telegram_admin_chat_id),
    el('p', { class: 'muted', text: 'Gets a private message whenever the Telegram webhook handler errors. Send /whoami to the bot to read off a chat id.' }),
    el('button', { class: 'primary', type: 'submit', text: 'Save settings' }),
    el('button', {
      type: 'button',
      onclick: async (event) => {
        const form = event.target.closest('form');
        const chatId = String(new FormData(form).get('telegram_admin_chat_id') || '').trim();
        try {
          const res = await api('POST', '/settings/telegram-test', chatId ? { chat_id: chatId } : {});
          const bot = res.telegram.bot;
          const sent = res.telegram.message_sent ? ` — test message sent to ${res.telegram.chat_id}` : ' — no chat id to message';
          toast(`Connected to @${bot.username}${sent}`);
        } catch (err) { toast(err.message, true); }
      },
      text: 'Test Telegram connection'
    })
  ]);

  show(form);
}

async function viewAccount() {
  chrome('Account', false);

  let me = {};
  try { me = await api('GET', '/auth/me'); } catch (err) { return showError(err); }

  const password = el('form', {
    class: 'card stack',
    onsubmit: async (event) => {
      event.preventDefault();
      const f = new FormData(event.target);
      const next = String(f.get('new_password'));
      if (next !== String(f.get('confirm'))) return toast('The two passwords do not match.', true);
      try {
        await api('POST', '/auth/password', {
          current_password: f.get('current_password'),
          new_password: next
        });
        event.target.reset();
        toast('Password changed. Your other sessions were signed out.');
      } catch (err) { toast(err.message, true); }
    }
  }, [
    el('h2', { text: 'Change my password' }),
    el('p', { class: 'muted', text: 'Every other session of yours is signed out, but this one stays.' }),
    field('Current password', 'current_password', '', 'password'),
    el('div', { class: 'grid2' }, [
      field('New password', 'new_password', '', 'password'),
      field('Confirm', 'confirm', '', 'password')
    ]),
    el('button', { class: 'primary', type: 'submit', text: 'Change password' })
  ]);

  show(
    el('div', { class: 'card' }, [
      el('h2', { text: 'Signed in' }),
      el('div', {}, [
        el('strong', { text: me.user.display_name || me.user.username }),
        ' ',
        el('span', { class: 'pill', text: me.user.role })
      ]),
      el('div', { class: 'muted mono', text: me.user.username }),
      el('div', { class: 'muted mono', text: API_BASE }),
      me.session && me.session.expires_at
        ? el('div', { class: 'muted', text: 'Session expires ' + when(me.session.expires_at) })
        : null,
      el('div', { class: 'row', style: 'margin-top:10px' }, [
        el('button', {
          class: 'danger',
          onclick: async () => {
            try { await api('POST', '/auth/logout'); } catch (err) { /* sign out locally regardless */ }
            store.clear();
            location.hash = '#/';
            route();
          },
          text: 'Sign out'
        })
      ])
    ]),
    password
  );
}

// ---------------------------------------------------------------------------
// Users (owner only)
// ---------------------------------------------------------------------------

async function viewUsers() {
  chrome('Users', false);
  busy();

  let data, sessions;
  try {
    data = await api('GET', '/users');
    sessions = await api('GET', '/sessions');
  } catch (err) {
    return showError(err);
  }

  const patch = async (id, payload, message) => {
    try {
      await api('PATCH', '/users/' + id, payload);
      toast(message || 'Saved');
      viewUsers();
    } catch (err) { toast(err.message, true); }
  };

  const rows = data.users.map((u) => el('div', { class: 'entry' }, [
    el('div', { class: 'row between' }, [
      el('div', { class: 'grow' }, [
        el('strong', { text: u.display_name || u.username }),
        ' ',
        el('span', { class: 'pill', text: u.role }),
        u.status !== 'active' ? el('span', { class: 'pill failed', text: u.status }) : null,
        u.locked_until && new Date(u.locked_until) > new Date()
          ? el('span', { class: 'pill failed', text: 'locked' })
          : null
      ])
    ]),
    el('div', { class: 'muted mono', text: u.username }),
    el('div', { class: 'muted', text:
      (u.last_login_at ? 'last sign in ' + when(u.last_login_at) : 'never signed in') +
      ' · ' + u.active_sessions + ' active session(s)' }),
    el('div', { class: 'row', style: 'margin-top:8px' }, [
      u.role === 'gateway' ? el('button', {
        class: 'small primary',
        onclick: async () => {
          if (!confirm('Issue a new gateway token? The old ones keep working until you revoke them.')) return;
          try {
            const result = await api('POST', '/users/' + u.id + '/tokens', { label: 'issued from admin app' });
            showToken(result.token);
          } catch (err) { toast(err.message, true); }
        },
        text: 'Issue token'
      }) : el('button', {
        class: 'small',
        onclick: () => {
          const next = prompt('New password for ' + u.username + ' (at least 10 characters):');
          if (!next) return;
          patch(u.id, { password: next }, 'Password reset; their sessions were signed out.');
        },
        text: 'Reset password'
      }),
      u.status === 'active'
        ? el('button', { class: 'small danger', onclick: () => patch(u.id, { status: 'disabled' }, 'Disabled'), text: 'Disable' })
        : el('button', { class: 'small', onclick: () => patch(u.id, { status: 'active' }, 'Enabled'), text: 'Enable' }),
      u.locked_until && new Date(u.locked_until) > new Date()
        ? el('button', { class: 'small', onclick: () => patch(u.id, { unlock: true }, 'Unlocked'), text: 'Unlock' })
        : null,
      el('button', {
        class: 'small danger',
        onclick: async () => {
          if (!confirm('Delete ' + u.username + '? This cannot be undone.')) return;
          try {
            await api('DELETE', '/users/' + u.id);
            toast('Deleted');
            viewUsers();
          } catch (err) { toast(err.message, true); }
        },
        text: 'Delete'
      })
    ])
  ]));

  const create = el('form', {
    class: 'card stack',
    onsubmit: async (event) => {
      event.preventDefault();
      const f = new FormData(event.target);
      const payload = {
        username: f.get('username'),
        display_name: f.get('display_name'),
        role: f.get('role')
      };
      if (payload.role !== 'gateway') payload.password = f.get('password');
      try {
        await api('POST', '/users', payload);
        event.target.reset();
        toast('Account created');
        viewUsers();
      } catch (err) { toast(err.message, true); }
    }
  }, [
    el('h2', { text: 'Add an account' }),
    el('div', { class: 'grid2' }, [
      field('Username', 'username', ''),
      field('Display name', 'display_name', '')
    ]),
    el('div', { class: 'grid2' }, [
      el('div', {}, [
        el('label', { text: 'Role' }),
        el('select', { name: 'role' }, [
          el('option', { value: 'admin', text: 'admin — a headhunter' }),
          el('option', { value: 'owner', text: 'owner — can manage accounts' }),
          el('option', { value: 'gateway', text: 'gateway — machine account, token only' })
        ])
      ]),
      field('Password (not for gateway)', 'password', '', 'password')
    ]),
    el('button', { class: 'primary', type: 'submit', text: 'Create' })
  ]);

  const active = el('div', { class: 'card' }, [
    el('h2', { text: 'Active sessions' }),
    ...(sessions.sessions.length ? sessions.sessions.map((s) => el('div', { class: 'entry' }, [
      el('div', { class: 'row between' }, [
        el('span', {}, [el('strong', { text: s.username }), ' ', el('span', { class: 'muted', text: s.label })]),
        el('button', {
          class: 'small danger',
          onclick: async () => {
            try {
              await api('DELETE', '/sessions/' + s.id);
              toast('Revoked');
              viewUsers();
            } catch (err) { toast(err.message, true); }
          },
          text: 'Revoke'
        })
      ]),
      el('div', { class: 'muted', text:
        'created ' + when(s.created_at) +
        (s.last_seen_at ? ' · last used ' + when(s.last_seen_at) : '') +
        ' · ' + (s.expires_at ? 'expires ' + when(s.expires_at) : 'never expires') })
    ])) : [el('p', { class: 'muted', text: 'None.' })])
  ]);

  show(el('div', { class: 'card' }, [el('h2', { text: 'Accounts' }), ...rows]), create, active);
}

/** A token is shown exactly once, so make it hard to lose. */
function showToken(token) {
  show(el('div', { class: 'card stack' }, [
    el('h2', { text: 'Gateway token' }),
    el('p', { text: 'Copy this now. Only its hash is stored, so it cannot be shown again.' }),
    el('textarea', { readonly: true, text: token, style: 'min-height:70px', onclick: (e) => e.target.select() }),
    el('p', { class: 'muted', text: 'Put it in the Apps Script property API_TOKEN.' }),
    el('button', { class: 'primary', onclick: () => viewUsers(), text: 'Done' })
  ]));
}

// ---------------------------------------------------------------------------
// Routing
// ---------------------------------------------------------------------------

function chrome(title, showBack) {
  const bar = document.getElementById('topbar');
  bar.hidden = false;
  document.getElementById('title').textContent = title;
  const back = document.getElementById('back');
  back.hidden = !showBack;
  back.onclick = () => history.back();

  for (const link of bar.querySelectorAll('nav a[data-owner-only]')) {
    link.hidden = store.role !== 'owner';
  }
  for (const link of bar.querySelectorAll('nav a')) {
    link.classList.toggle('active', location.hash.startsWith(link.getAttribute('href')));
  }
}

function showError(err) {
  if (err.status === 401) {
    toast('Sign in again.', true);
    store.clear();
    return viewLogin();
  }
  show(el('div', { class: 'card stack' }, [
    el('h2', { text: 'Something went wrong' }),
    el('p', { text: err.message }),
    el('button', { onclick: () => route(), text: 'Retry' })
  ]));
}

function route() {
  clearTimeout(viewRun.timer);

  if (!store.token) return viewLogin();

  const hash = location.hash || '#/inbox';
  const run = hash.match(/^#\/run\/(\d+)/);
  const candidate = hash.match(/^#\/candidate\/(\d+)/);

  if (run) return viewRun(run[1]);
  if (candidate) return viewCandidate(candidate[1]);
  if (hash.startsWith('#/settings')) return viewSettings();
  if (hash.startsWith('#/account')) return viewAccount();
  if (hash.startsWith('#/users')) return viewUsers();
  return viewInbox();
}

window.addEventListener('hashchange', route);
window.addEventListener('load', () => {
  route();
  if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('sw.js').catch(() => {});
  }
});
