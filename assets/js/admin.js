(() => {
  "use strict";
  const app = document.getElementById("wpmd-app");
  const api = window.wp.apiFetch;
  api.use(api.createNonceMiddleware(WPMD.nonce));
  const state = {
    data: null,
    view: "mail",
    account: 0,
    folder: 0,
    folders: [],
    messages: [],
    selected: null,
    search: "",
    offset: 0,
    more: false,
    loading: false,
    notice: "",
    error: false,
    records: [],
    sequence: 0,
    readerSequence: 0,
  };
  const esc = (value) =>
    String(value ?? "").replace(
      /[&<>"\x27]/g,
      (c) =>
        ({
          "&": "&amp;",
          "<": "&lt;",
          ">": "&gt;",
          '"': "&quot;",
          "\x27": "&#039;",
        })[c],
    );
  const json = (value, fallback = []) => {
    try {
      return JSON.parse(value || "null") ?? fallback;
    } catch {
      return fallback;
    }
  };
  const req = (path, options = {}) =>
    api({ path: "/wpmd/v1/" + path, ...options });
  const can = (cap) => state.data?.capabilities?.includes("wpmd_" + cap);
  const textFromHtml = (html) =>
    new DOMParser().parseFromString(html || "", "text/html").body.textContent ||
    "";
  const addresses = (input) =>
    Array.isArray(input)
      ? input.map((a) => (typeof a === "string" ? a : a.email)).join(", ")
      : input || "";
  const date = (value) => {
    if (!value) return "";
    const d = new Date(value.replace(" ", "T") + "Z");
    return Number.isNaN(d.getTime()) ? value : d.toLocaleString();
  };
  const accountName = (id) =>
    state.data.accounts.find((a) => +a.id === +id)?.email || "Removed account";
  const button = (action, label, id = "", css = "") =>
    `<button type="button" data-action="${action}" data-id="${esc(id)}" class="${css}">${label}</button>`;
  const empty = (title, detail = "") =>
    `<div class="empty"><h2>${esc(title)}</h2><p>${esc(detail)}</p></div>`;
  function notice(message, error = false) {
    state.notice = message;
    state.error = error;
    const el = app.querySelector(".wpmd-notice");
    if (el) {
      el.textContent = message;
      el.classList.toggle("error", error);
      el.hidden = !message;
    }
  }
  async function refreshData() {
    state.data = await req("bootstrap");
  }
  async function savedAndRefresh(close, message) {
    close();
    notice(message);
    try {
      await refreshData();
      render();
    } catch {
      notice(message + " Refresh the page to load the updated list.");
    }
  }
  async function boot() {
    try {
      await refreshData();
      state.account = +(state.data.accounts[0]?.id || 0);
      render();
      await loadMailbox();
    } catch (error) {
      app.innerHTML = `<div class="wpmd-error" role="alert">${esc(error.message || "Unable to load MailDesk.")} ${button("retry", "Try again")}</div>`;
    }
  }
  function render() {
    if (!state.data) return;
    const views = [
      ["mail", "Mail", "read_mail"],
      ["drafts", "Drafts", "compose_mail"],
      ["outbox", "Outbox", "send_mail"],
      ["contacts", "Contacts", "manage_contacts"],
      ["resources", "Writing tools", "compose_mail"],
      ["settings", "Accounts", "access_mail"],
      ["diagnostics", "Diagnostics", "view_diagnostics"],
    ];
    app.innerHTML = `<div class="wpmd-shell"><header><strong class="brand">MailDesk</strong><nav aria-label="MailDesk">${views
      .filter((v) => can(v[2]))
      .map(
        ([id, label]) =>
          `<button type="button" data-action="view" data-id="${id}" aria-current="${state.view === id ? "page" : "false"}" class="${state.view === id ? "active" : ""}">${label}</button>`,
      )
      .join(
        "",
      )}</nav>${can("compose_mail") ? button("compose", "Compose", "", "wpmd-primary") : ""}</header><div class="wpmd-notice ${state.error ? "error" : ""}" role="status" aria-live="polite" ${state.notice ? "" : "hidden"}>${esc(state.notice)}</div>${state.view === "mail" ? mail() : page()}</div>`;
    renderFrame();
  }
  function mail() {
    if (!can("read_mail"))
      return empty(
        "Mail access is unavailable",
        "Ask your WordPress administrator for mail permissions.",
      );
    return `<main class="mail-layout ${state.selected ? "reading" : ""}"><aside class="sidebar"><label for="accountSelect">Mailbox</label><select id="accountSelect"><option value="0">All accounts</option>${state.data.accounts.map((a) => `<option value="${a.id}" ${+a.id === state.account ? "selected" : ""}>${esc(a.label || a.email)}</option>`).join("")}</select><nav class="folders" aria-label="Folders">${folderList()}</nav></aside><section class="list-pane" aria-label="Messages"><div class="toolbar"><label class="screen-reader-text" for="mailSearch">Search mail</label><input id="mailSearch" type="search" value="${esc(state.search)}" placeholder="Search mail">${button("refresh", "Refresh")}</div><div id="messageList" aria-busy="${state.loading}">${messageList()}</div><div class="pagination">${state.more ? button("more", "Load more") : ""}</div></section><section class="reader" id="reader" aria-label="Message reader">${reader()}</section></main>`;
  }
  function folderList() {
    if (!state.account)
      return `<p class="muted">Messages from all your accounts.</p>`;
    const a = state.data.accounts.find((a) => +a.id === state.account);
    return (
      state.folders
        .map(
          (f) =>
            `<button type="button" data-action="folder" data-id="${f.id}" class="${+f.id === state.folder ? "active" : ""}" aria-current="${+f.id === state.folder ? "true" : "false"}"><span>${esc(f.display_name)}</span><small>${+f.unread_count || ""}</small></button>`,
        )
        .join("") +
      (a?.can_manage && can("manage_own_accounts")
        ? button("sync", "Sync account", state.account, "sync")
        : "") +
      (!state.folders.length
        ? `<p class="muted">${state.loading ? "Loading folders…" : "Sync this account to discover its folders."}</p>`
        : "")
    );
  }
  function messageList() {
    if (!state.messages.length)
      return empty(
        state.loading ? "Loading messages…" : "No messages",
        state.search
          ? "Try another search."
          : "Choose a mailbox or sync an account.",
      );
    return state.messages
      .map((m) => {
        const from = json(m.from_json)[0] || {};
        return `<button type="button" class="message-row ${+m.is_read ? "" : "unread"} ${+state.selected?.id === +m.id ? "selected" : ""}" data-action="message" data-id="${m.id}"><span class="avatar" aria-hidden="true">${esc((from.name || from.email || "?").slice(0, 1))}</span><span class="msg-main"><b>${esc(from.name || from.email || "Unknown sender")}</b><strong>${+m.is_starred ? "★ " : ""}${esc(m.subject || "(No subject)")}</strong><small>${esc(m.body_preview)}</small></span><time>${esc(date(m.received_at))}${+m.has_attachments ? " 📎" : ""}</time></button>`;
      })
      .join("");
  }
  function reader() {
    const m = state.selected;
    if (!m)
      return empty(
        "Select a message",
        "Choose an email from the list to read it.",
      );
    const from = json(m.from_json)[0] || {};
    return `<article><div class="reader-head">${button("back", "← Back to messages", "", "reader-back")}<h2 tabindex="-1" id="messageTitle">${esc(m.subject || "(No subject)")}</h2><div class="actions">${can("compose_mail") ? button("reply", "Reply") + button("forward", "Forward") : ""}${m.movable ? button("move", "Move / Trash") : ""}${m.writable ? button("star", +m.is_starred ? "Unstar" : "Star") + button("read", +m.is_read ? "Mark unread" : "Mark read") : ""}</div><p><b>${esc(from.name || from.email || "Unknown sender")}</b> &lt;${esc(from.email)}&gt;</p><p>To: ${esc(addresses(json(m.to_json)))}</p><small>${esc(date(m.received_at))} · ${esc(accountName(m.account_id))}</small></div>${m.attachments?.length ? `<ul class="attachments">${m.attachments.map((a) => `<li>${button("attachment", `${esc(a.filename)} (${Math.ceil(+a.size_bytes / 1024)} KB)`, a.id)}</li>`).join("")}</ul>` : ""}<iframe id="mailFrame" sandbox="allow-popups allow-popups-to-escape-sandbox" referrerpolicy="no-referrer" title="Email content"></iframe></article>`;
  }
  function renderFrame() {
    const frame = app.querySelector("#mailFrame");
    if (!frame || !state.selected) return;
    const content =
      state.selected.body_html_safe ||
      `<pre>${esc(state.selected.body_text || "This message has no text content.")}</pre>`;
    frame.srcdoc = `<!doctype html><html><head><meta charset="utf-8"><meta http-equiv="Content-Security-Policy" content="default-src &#39;none&#39;; style-src &#39;unsafe-inline&#39;; img-src &#39;none&#39;; form-action &#39;none&#39;; base-uri &#39;none&#39;"><base target="_blank"><style>body{font:15px/1.65 system-ui;margin:24px;color:#172033;overflow-wrap:anywhere}pre{white-space:pre-wrap;font:inherit}table{max-width:100%}a{color:#244bd7}</style></head><body>${content}</body></html>`;
  }
  function page() {
    let title = "",
      actions = "",
      content = "";
    if (state.view === "settings") {
      title = "Email accounts";
      actions = can("manage_own_accounts")
        ? button("add-account", "Add account", "", "wpmd-primary")
        : "";
      content =
        state.data.accounts
          .map(
            (a) =>
              `<div class="setting-row"><div><h2>${esc(a.label || a.email)}</h2><p>${esc(a.email)} · ${esc(a.status)}</p>${a.last_error ? `<p class="error-text">${esc(a.last_error)}</p>` : ""}<small>Last sync: ${esc(date(a.last_sync_at) || "Never")}</small></div><div class="actions">${a.can_manage && can("manage_own_accounts") ? button("edit-account", "Edit", a.id) + button("test", "Test", a.id) + button("sync", "Sync", a.id) + button("delete-account", "Remove", a.id) : ""}${a.can_manage && can("manage_shared_accounts") ? button("shares", "Shared access", a.id) : ""}</div></div>`,
          )
          .join("") ||
        empty(
          "No accounts configured",
          "Add your IMAP and SMTP settings to get started.",
        );
    } else if (state.view === "contacts") {
      title = "Contacts";
      actions = button("add-contact", "Add contact", "", "wpmd-primary");
      content = `<div class="cards">${state.data.contacts.map((c) => `<div class="card"><h2>${esc(c.display_name || `${c.first_name} ${c.last_name}`)}</h2><p>${esc(addresses(json(c.emails_json)))}</p><p>${esc(c.company)}</p><div class="actions">${button("contact-mail", "Write", c.id)}${button("edit-contact", "Edit", c.id)}${button("delete-contact", "Delete", c.id)}</div></div>`).join("") || empty("No contacts yet")}</div>`;
    } else if (state.view === "drafts") {
      title = "Drafts";
      actions = button("reload-page", "Refresh");
      content =
        state.records
          .map((d) => {
            const p = json(d.data_json, {});
            return `<div class="setting-row"><div><h2>${esc(p.subject || "(No subject)")}</h2><p>${esc(addresses(p.to))} · ${esc(accountName(d.account_id))}</p><small>${esc(date(d.updated_at))}</small></div><div>${button("open-draft", "Open draft", d.id)}${button("delete-draft", "Delete", d.id)}</div></div>`;
          })
          .join("") ||
        empty(state.loading ? "Loading drafts…" : "No saved drafts");
    } else if (state.view === "outbox") {
      title = "Outbox";
      actions = button("reload-page", "Refresh");
      content = `<p>Queued mail is processed by WordPress cron. Sent means the SMTP server accepted it. For uncertain delivery, check with your provider or recipient before sending again.</p>${state.records.map((o) => `<div class="setting-row"><div><h2>Message #${o.id} · ${esc(o.status)}</h2><p>${esc(accountName(o.account_id))} · ${esc(date(o.sent_at || o.scheduled_at))}</p>${o.last_error ? `<p class="error-text">${esc(o.last_error)}</p>` : ""}</div>${["queued", "retrying"].includes(o.status) ? button("cancel-send", "Cancel delivery", o.id) : ""}</div>`).join("") || empty("No outgoing messages")}`;
    } else if (state.view === "resources") {
      title = "Writing tools";
      content = [
        ["signatures", "Signatures", "compose_mail"],
        ["templates", "Templates", "manage_templates"],
        ["rules", "Rules", "manage_rules"],
      ]
        .filter((x) => can(x[2]))
        .map(
          ([type, label]) =>
            `<section class="setting-card"><div class="page-head"><h2>${label}</h2>${button("add-resource", "Add " + label.toLowerCase().replace(/s$/, ""), type)}</div>${type === "rules" ? "<p>Rules apply to newly cached messages in accounts you own. All conditions must match. Actions can mark messages read or starred.</p>" : ""}${state.data[type].map((r) => `<div class="setting-row"><span>${esc(r.name)}${type === "rules" && !+r.enabled ? " (disabled)" : ""}</span><div>${button("edit-resource", "Edit", `${type}:${r.id}`)}${button("delete-resource", "Delete", `${type}:${r.id}`)}</div></div>`).join("")}</section>`,
        )
        .join("");
    } else if (state.view === "diagnostics") {
      title = "Diagnostics";
      actions = button("diagnostics", "Run diagnostics");
      content = `<div id="diag"><p>Inspect runtime, background processing and database health.</p></div>`;
    }
    return `<main class="page"><div class="page-head"><h1>${title}</h1>${actions}</div>${content}</main>`;
  }
  async function navigate(view) {
    clearTimeout(searchTimer);
    state.notice = "";
    state.error = false;
    state.sequence++;
    state.readerSequence++;
    state.view = view;
    state.records = [];
    state.loading = false;
    render();
    if (view === "mail") await loadMailbox();
    else if (["drafts", "outbox"].includes(view)) {
      const sequence = ++state.sequence;
      state.loading = true;
      render();
      try {
        const records = await req(view);
        if (sequence !== state.sequence) return;
        state.records = records;
      } finally {
        if (sequence === state.sequence) {
          state.loading = false;
          render();
        }
      }
    }
  }
  async function loadMailbox() {
    if (state.view !== "mail" || !can("read_mail")) return;
    const sequence = ++state.sequence;
    const account = state.account;
    state.loading = true;
    state.selected = null;
    state.readerSequence++;
    render();
    try {
      const folders = account ? await req(`accounts/${account}/folders`) : [];
      if (sequence !== state.sequence) return;
      state.folders = folders;
      if (!folders.some((f) => +f.id === state.folder))
        state.folder = +(
          folders.find((f) => f.remote_name.toUpperCase() === "INBOX")?.id ||
          folders[0]?.id ||
          0
        );
      state.offset = 0;
      const messages = await req(
        `messages?account_id=${account}&folder_id=${state.folder}&search=${encodeURIComponent(state.search)}&limit=50`,
      );
      if (sequence !== state.sequence) return;
      state.messages = messages;
      state.more = messages.length === 50;
    } finally {
      if (sequence === state.sequence) {
        state.loading = false;
        render();
      }
    }
  }
  async function loadMessages(more = false) {
    const sequence = ++state.sequence;
    const offset = more ? state.messages.length : 0;
    state.loading = true;
    const list = app.querySelector("#messageList");
    if (list) list.setAttribute("aria-busy", "true");
    try {
      const messages = await req(
        `messages?account_id=${state.account}&folder_id=${state.folder}&search=${encodeURIComponent(state.search)}&offset=${offset}&limit=50`,
      );
      if (sequence !== state.sequence || state.view !== "mail") return;
      state.messages = more ? [...state.messages, ...messages] : messages;
      state.offset = offset;
      state.more = messages.length === 50;
      if (!more) {
        state.selected = null;
        state.readerSequence++;
      }
      updateMailParts();
    } finally {
      if (sequence === state.sequence) {
        state.loading = false;
        app.querySelector("#messageList")?.setAttribute("aria-busy", "false");
      }
    }
  }
  function updateMailParts() {
    const list = app.querySelector("#messageList");
    if (list) list.innerHTML = messageList();
    const readerEl = app.querySelector("#reader");
    if (readerEl) readerEl.innerHTML = reader();
    const folders = app.querySelector(".folders");
    if (folders) folders.innerHTML = folderList();
    const pagination = app.querySelector(".pagination");
    if (pagination)
      pagination.innerHTML = state.more ? button("more", "Load more") : "";
    app
      .querySelector(".mail-layout")
      ?.classList.toggle("reading", !!state.selected);
    renderFrame();
  }
  async function openMessage(id) {
    const sequence = ++state.readerSequence;
    const viewSequence = state.sequence;
    const message = await req(`messages/${id}`);
    if (
      sequence !== state.readerSequence ||
      state.view !== "mail" ||
      viewSequence !== state.sequence
    )
      return;
    state.selected = message;
    updateMailParts();
    app.querySelector("#messageTitle")?.focus();
    if (!+message.is_read && message.writable) {
      try {
        await req(`messages/${id}/state`, {
          method: "POST",
          data: { is_read: true },
        });
        if (sequence !== state.readerSequence) return;
        updateLocalState(id, { is_read: 1 });
      } catch (error) {
        if (sequence === state.readerSequence)
          notice("Message opened. " + error.message, true);
      }
    }
  }
  function updateLocalState(id, fields) {
    const item = state.messages.find((m) => +m.id === +id);
    if (
      item &&
      fields.is_read !== undefined &&
      +item.is_read !== +fields.is_read
    ) {
      const folder = state.folders.find((f) => +f.id === +item.folder_id);
      if (folder)
        folder.unread_count = Math.max(
          0,
          +folder.unread_count + (+fields.is_read ? -1 : 1),
        );
    }
    if (item) Object.assign(item, fields);
    if (+state.selected?.id === +id) Object.assign(state.selected, fields);
    updateMailParts();
  }
  let activeDialog = null;
  function dialog(title, html, onSubmit) {
    if (activeDialog) return;
    const previousFocus = document.activeElement;
    const d = document.createElement("dialog");
    d.className = "wpmd-dialog";
    d.innerHTML = `<form><div class="compose-head"><h2 id="wpmd-dialog-title">${esc(title)}</h2><button type="button" data-close aria-label="Close dialog">×</button></div><div class="dialog-body">${html}</div><div class="compose-foot"><button type="submit" class="wpmd-primary">Save</button><button type="button" data-close>Cancel</button><span role="status" aria-live="polite" data-status></span></div></form>`;
    d.setAttribute("aria-labelledby", "wpmd-dialog-title");
    document.body.appendChild(d);
    activeDialog = d;
    let dirty = false,
      busy = false;
    const close = (force = false) => {
      if (busy && !force) return;
      if (!force && dirty && !window.confirm("Discard unsaved changes?"))
        return;
      d.close();
      d.remove();
      activeDialog = null;
      previousFocus?.focus();
    };
    d.addEventListener("input", () => {
      dirty = true;
    });
    d.querySelectorAll("[data-close]").forEach(
      (b) => (b.onclick = () => close()),
    );
    d.addEventListener("cancel", (e) => {
      e.preventDefault();
      close();
    });
    d.querySelector("form").onsubmit = async (e) => {
      e.preventDefault();
      if (busy) return;
      const values = Object.fromEntries(new FormData(e.currentTarget));
      busy = true;
      d.querySelectorAll("button,input,select,textarea").forEach((b) => {
        b.disabled = true;
      });
      const status = d.querySelector("[data-status]");
      status.textContent = "Saving…";
      try {
        await onSubmit(values, d, () => close(true));
        dirty = false;
      } catch (error) {
        status.textContent = error.message || "The request failed. Try again.";
      } finally {
        busy = false;
        d.querySelectorAll("button,input,select,textarea").forEach((b) => {
          b.disabled = false;
        });
      }
    };
    d.showModal();
    return {
      el: d,
      close: () => close(true),
      saved: () => {
        dirty = false;
      },
    };
  }
  const field = (label, name, value = "", type = "text", extra = "") =>
    `<label>${label}<input aria-label="${esc(label)}" name="${name}" type="${type}" value="${esc(value)}" ${extra}></label>`;
  const textarea = (label, name, value = "", extra = "") =>
    `<label>${label}<textarea aria-label="${esc(label)}" name="${name}" ${extra}>${esc(value)}</textarea></label>`;
  const accountOptions = (selected, filter = () => true, all = false) =>
    `${all ? `<option value="0">All my accounts</option>` : ""}${state.data.accounts
      .filter(filter)
      .map(
        (a) =>
          `<option value="${a.id}" ${+a.id === +selected ? "selected" : ""}>${esc(a.email)}</option>`,
      )
      .join("")}`;
  function accountModal(id = 0) {
    const a = state.data.accounts.find((a) => +a.id === +id) || {};
    let content =
      field(
        "Account label",
        "label",
        a.label,
        "text",
        "required maxlength=190",
      ) +
      field("Email", "email", a.email, "email", "required") +
      field("Display name", "display_name", a.display_name) +
      field("Username (leave empty to use email)", "username", a.username) +
      field(
        id
          ? "Password / app password (leave empty to keep)"
          : "Password / app password",
        "secret",
        "",
        "password",
        `${id ? "" : "required"} autocomplete="new-password"`,
      );
    for (const kind of ["imap", "smtp"])
      content += `<fieldset><legend>${kind.toUpperCase()}</legend><div class="grid2">${field("Host", kind + "_host", a[kind + "_host"], "text", "required")}${field("Port", kind + "_port", a[kind + "_port"] || (kind === "imap" ? 993 : 465), "number", "required min=1 max=65535")}</div><label>Security<select name="${kind}_security"><option value="ssl" ${a[kind + "_security"] !== "tls" ? "selected" : ""}>TLS on connect (usually ${kind === "imap" ? "993" : "465"})</option><option value="tls" ${a[kind + "_security"] === "tls" ? "selected" : ""}>STARTTLS (usually ${kind === "imap" ? "143" : "587"})</option></select></label></fieldset>`;
    content +=
      field(
        "History to cache (days, latest 250 messages per folder)",
        "sync_days",
        a.sync_days || 30,
        "number",
        "required min=1 max=3650",
      ) +
      `<label class="check"><input type="checkbox" name="sync_enabled" ${!id || +a.sync_enabled ? "checked" : ""}>Enable automatic synchronization</label>`;
    dialog(
      id ? "Edit account" : "Add email account",
      content,
      async (data, d, close) => {
        await req("accounts", {
          method: "POST",
          data: {
            ...data,
            id,
            auth_type: a.auth_type || "password",
            sync_enabled: data.sync_enabled === "on",
          },
        });
        await savedAndRefresh(
          close,
          "Account saved. Test the connection, then sync to load mail.",
        );
        if (!state.account) state.account = +(state.data.accounts[0]?.id || 0);
      },
    );
  }
  function compose(pref = {}) {
    const accounts = state.data.accounts.filter((a) => a.can_compose);
    if (pref.account_id && !accounts.some((a) => +a.id === +pref.account_id)) {
      notice(
        "You do not have compose permission for this message’s account.",
        true,
      );
      return;
    }
    if (!accounts.length) {
      notice("Add an account or request compose permission first.", true);
      return;
    }
    const account =
      accounts.find((a) => +a.id === +(pref.account_id || state.account)) ||
      accounts[0];
    let draftId = pref.draft_id || 0,
      version = pref.draft_version || 1,
      files = pref.attachments || [];
    const requestId = crypto.randomUUID();
    const body = pref.body_text || textFromHtml(pref.body_html);
    const html = `<label>From<select name="account_id">${accountOptions(account.id, (a) => a.can_compose)}</select></label>${field("To (comma-separated emails)", "to", addresses(pref.to))}<div class="grid2">${field("Cc", "cc", addresses(pref.cc))}${field("Bcc", "bcc", addresses(pref.bcc))}</div>${field("Subject", "subject", pref.subject)}${textarea("Message", "body_text", body, "rows=12")}<div class="grid2"><label>Insert template<select data-template><option value="">Choose a template</option>${state.data.templates.map((t) => `<option value="${t.id}">${esc(t.name)}</option>`).join("")}</select></label><label>Insert signature<select data-signature><option value="">Choose a signature</option>${state.data.signatures
      .filter((s) => !+s.account_id || +s.account_id === +account.id)
      .map((s) => `<option value="${s.id}">${esc(s.name)}</option>`)
      .join(
        "",
      )}</select></label></div><label>Attachments (20 files, 10 MiB total)<input type="file" multiple data-files></label><ul data-file-list></ul>${field("Send later (your local time; optional)", "scheduled_at", "", "datetime-local")}<input type="hidden" name="intent" value="send">`;
    const modal = dialog(
      draftId ? "Edit draft" : "New message",
      html,
      async (data, d, close) => {
        if (filePromise) await filePromise;
        const payload = {
          ...data,
          account_id: +data.account_id,
          attachments: files,
          in_reply_to: pref.in_reply_to || "",
          references: pref.references || "",
        };
        const status = d.querySelector("[data-status]");
        if (data.intent === "draft") {
          const saved = await req("drafts", {
            method: "POST",
            data: {
              id: draftId,
              version,
              account_id: payload.account_id,
              data: payload,
            },
          });
          draftId = saved.id;
          version = saved.version;
          status.textContent = "Draft saved";
          modal.saved();
        } else {
          const selectedAccount = state.data.accounts.find(
            (a) => +a.id === payload.account_id,
          );
          if (!can("send_mail") || !selectedAccount?.can_send)
            throw new Error(
              "You do not have permission to send from this account.",
            );
          status.textContent = "Queueing…";
          await req("send", {
            method: "POST",
            data: {
              ...payload,
              request_id: requestId,
              draft_id: draftId,
              draft_version: version,
              scheduled_at: data.scheduled_at
                ? new Date(data.scheduled_at).toISOString()
                : "",
            },
          });
          close();
          notice("Message queued. Follow its delivery in Outbox.");
          if (state.view === "drafts") await navigate("drafts");
        }
      },
    );
    if (!modal) return;
    const d = modal.el,
      submit = d.querySelector("[type=submit]");
    submit.textContent = "Send";
    submit.onclick = () => {
      d.querySelector("[name=intent]").value = "send";
    };
    const save = document.createElement("button");
    save.type = "submit";
    save.textContent = "Save draft";
    save.onclick = () => {
      d.querySelector("[name=intent]").value = "draft";
    };
    submit.after(save);
    let filePromise = null;
    const showFiles = () => {
      d.querySelector("[data-file-list]").innerHTML = files
        .map(
          (f, i) =>
            `<li>${esc(f.filename)} <button type="button" data-remove-file="${i}" aria-label="Remove ${esc(f.filename)}">Remove</button></li>`,
        )
        .join("");
    };
    showFiles();
    d.querySelector("[data-file-list]").onclick = (e) => {
      const b = e.target.closest("[data-remove-file]");
      if (b) {
        files.splice(+b.dataset.removeFile, 1);
        showFiles();
        d.dispatchEvent(new Event("input", { bubbles: true }));
      }
    };
    d.querySelector("[data-files]").onchange = (e) => {
      const selected = Array.from(e.target.files);
      e.target.value = "";
      filePromise = (async () => {
        const oldSize = files.reduce(
          (total, f) => total + Math.floor((f.content_base64.length * 3) / 4),
          0,
        );
        if (
          files.length + selected.length > 20 ||
          oldSize + selected.reduce((sum, f) => sum + f.size, 0) > 10485760
        )
          throw new Error(
            "Attachments are limited to 20 files and 10 MiB total.",
          );
        const added = await Promise.all(
          selected.map(
            (file) =>
              new Promise((resolve, reject) => {
                const reader = new FileReader();
                reader.onload = () =>
                  resolve({
                    filename: file.name,
                    content_base64: String(reader.result).split(",")[1],
                  });
                reader.onerror = () =>
                  reject(new Error("Unable to read attachment."));
                reader.readAsDataURL(file);
              }),
          ),
        );
        files = [...files, ...added];
        showFiles();
      })();
      filePromise
        .catch((error) => {
          d.querySelector("[data-status]").textContent = error.message;
        })
        .finally(() => {
          filePromise = null;
        });
    };
    d.querySelector("[data-template]").onchange = (e) => {
      const t = state.data.templates.find((t) => +t.id === +e.target.value);
      if (t) {
        d.querySelector("[name=subject]").value = t.subject;
        d.querySelector("[name=body_text]").value =
          t.body_text || textFromHtml(t.body_html);
      }
    };
    d.querySelector("[data-signature]").onchange = (e) => {
      const s = state.data.signatures.find((s) => +s.id === +e.target.value);
      if (s)
        d.querySelector("[name=body_text]").value +=
          "\n\n" + (s.text || textFromHtml(s.html));
    };
    d.querySelector("[name=account_id]").onchange = (e) => {
      d.querySelector("[data-signature]").innerHTML =
        `<option value="">Choose a signature</option>${state.data.signatures
          .filter((s) => !+s.account_id || +s.account_id === +e.target.value)
          .map((s) => `<option value="${s.id}">${esc(s.name)}</option>`)
          .join("")}`;
    };
    if (!draftId && !body) {
      const signature =
        state.data.signatures.find(
          (s) => +s.is_default && +s.account_id === +account.id,
        ) || state.data.signatures.find((s) => +s.is_default && !+s.account_id);
      if (signature)
        d.querySelector("[name=body_text]").value =
          "\n\n" + (signature.text || textFromHtml(signature.html));
    }
  }
  function contactModal(id = 0) {
    const c = state.data.contacts.find((c) => +c.id === +id) || {};
    dialog(
      id ? "Edit contact" : "Add contact",
      field(
        "Display name",
        "display_name",
        c.display_name,
        "text",
        "required maxlength=190",
      ) +
        field(
          "Email addresses (comma-separated)",
          "emails",
          addresses(json(c.emails_json)),
        ) +
        field("Company", "company", c.company) +
        field("Website", "website", c.website, "url") +
        textarea("Notes", "notes", c.notes),
      async (data, d, close) => {
        await req("contacts", {
          method: "POST",
          data: {
            ...c,
            ...data,
            id,
            emails: data.emails
              .split(/[,;]+/)
              .map((s) => s.trim())
              .filter(Boolean),
            phones: json(c.phones_json),
            tags: json(c.tags_json),
          },
        });
        await savedAndRefresh(close, "Contact saved.");
      },
    );
  }
  function resourceModal(type, id = 0) {
    const r = state.data[type].find((r) => +r.id === +id) || {};
    let html = field("Name", "name", r.name, "text", "required maxlength=190");
    if (type === "templates")
      html +=
        field("Subject", "subject", r.subject) +
        textarea(
          "Message",
          "body_text",
          r.body_text || textFromHtml(r.body_html),
          "rows=8",
        );
    if (type === "signatures")
      html +=
        `<label>Account<select name="account_id">${accountOptions(r.account_id, (a) => a.can_compose, true)}</select></label>` +
        textarea(
          "Signature",
          "text",
          r.text || textFromHtml(r.html),
          "rows=5",
        ) +
        `<label class="check"><input name="is_default" type="checkbox" ${+r.is_default ? "checked" : ""}>Use by default</label>`;
    if (type === "rules") {
      const conditions = json(r.conditions_json);
      const actions = json(r.actions_json, {});
      const rows = conditions.length
        ? conditions
        : [{ field: "subject", value: "" }];
      html += `<label>Account<select name="account_id">${accountOptions(r.account_id, (a) => +a.owner_user_id === +WPMD.user, true)}</select></label>${rows.map((condition, index) => `<div class="grid2"><label>Match field<select name="match_field_${index}"><option value="subject" ${condition.field === "subject" ? "selected" : ""}>Subject</option><option value="from" ${condition.field === "from" ? "selected" : ""}>From</option></select></label>${field("Contains", "match_value_" + index, condition.value, "text", "required maxlength=200")}</div>`).join("")}${["is_read", "is_starred"].map((key) => `<label>${key === "is_read" ? "Read status" : "Star status"}<select name="action_${key}"><option value="">Leave unchanged</option><option value="true" ${actions[key] === true ? "selected" : ""}>${key === "is_read" ? "Mark read" : "Star"}</option><option value="false" ${actions[key] === false ? "selected" : ""}>${key === "is_read" ? "Mark unread" : "Unstar"}</option></select></label>`).join("")}${field("Priority (lower runs first)", "priority", r.priority ?? 100, "number", "min=-2147483648 max=2147483647")}<label class="check"><input name="enabled" type="checkbox" ${!id || +r.enabled ? "checked" : ""}>Enabled</label>`;
    }
    dialog(
      `${id ? "Edit" : "Add"} ${type.replace(/s$/, "")}`,
      html,
      async (data, d, close) => {
        const payload = {
          ...data,
          id,
          account_id: +data.account_id || 0,
          is_default: data.is_default === "on",
          enabled: data.enabled === "on",
        };
        if (type === "signatures") payload.html = "";
        if (type === "templates") payload.body_html = "";
        if (type === "rules") {
          payload.conditions = Object.keys(data)
            .filter((key) => key.startsWith("match_field_"))
            .map((key) => ({
              field: data[key],
              value: data[key.replace("match_field_", "match_value_")],
            }));
          payload.actions = Object.fromEntries(
            ["is_read", "is_starred"]
              .filter((key) => data["action_" + key] !== "")
              .map((key) => [key, data["action_" + key] === "true"]),
          );
        }
        await req(type, { method: "POST", data: payload });
        await savedAndRefresh(close, "Saved.");
      },
    );
  }
  async function sharesModal(id) {
    const shares = await req(`accounts/${id}/shares`);
    const html = `<p>Use an existing WordPress user ID. That user also needs MailDesk capabilities from the site administrator.</p><ul>${shares.map((s) => `<li>User ${s.user_id}: ${esc(json(s.permissions).join(", "))}</li>`).join("") || "<li>No shared users.</li>"}</ul>${field("WordPress user ID", "user_id", "", "number", "required min=1")}<p>Select permissions, or clear all to revoke access.</p>${["read", "write", "compose", "send", "delete"].map((p) => `<label class="check"><input name="${p}" type="checkbox" ${p === "read" ? "checked" : ""}>${p}</label>`).join("")}`;
    dialog("Shared account access", html, async (data, d, close) => {
      await req(`accounts/${id}/shares`, {
        method: "POST",
        data: {
          user_id: +data.user_id,
          permissions: ["read", "write", "compose", "send", "delete"].filter(
            (p) => data[p] === "on",
          ),
        },
      });
      close();
      notice("Shared access updated.");
    });
  }
  async function act(action, id) {
    switch (action) {
      case "retry":
        return boot();
      case "view":
        return navigate(id);
      case "compose":
        return compose();
      case "add-account":
        return accountModal();
      case "edit-account":
        return accountModal(+id);
      case "add-contact":
        return contactModal();
      case "edit-contact":
        return contactModal(+id);
      case "shares":
        return sharesModal(+id);
      case "add-resource":
        return resourceModal(id);
      case "edit-resource": {
        const [type, item] = id.split(":");
        return resourceModal(type, +item);
      }
      case "folder":
        state.folder = +id;
        return loadMessages();
      case "message":
        return openMessage(+id);
      case "refresh":
        return loadMailbox();
      case "more":
        return loadMessages(true);
      case "reload-page":
        return navigate(state.view);
      case "back":
        state.readerSequence++;
        state.selected = null;
        updateMailParts();
        app.querySelector(".message-row")?.focus();
        return;
      case "sync":
        await req(`accounts/${id}/sync`, { method: "POST" });
        notice(
          "Synchronization queued. Refresh after the background worker runs.",
        );
        return;
      case "test": {
        const result = await req(`accounts/${id}/test`, { method: "POST" });
        notice(
          ["imap", "smtp"]
            .map(
              (k) =>
                `${k.toUpperCase()}: ${result[k].ok ? "connected" : result[k].error}`,
            )
            .join(" · "),
          !result.imap.ok || !result.smtp.ok,
        );
        return;
      }
      case "star":
      case "read": {
        const m = state.selected;
        if (!m) return;
        const key = action === "star" ? "is_starred" : "is_read";
        const value = !+m[key];
        await req(`messages/${m.id}/state`, {
          method: "POST",
          data: { [key]: value },
        });
        updateLocalState(m.id, { [key]: +value });
        return;
      }
      case "reply":
      case "forward": {
        const m = state.selected;
        if (!m) return;
        const recipient =
          json(m.reply_to_json)[0] || json(m.from_json)[0] || {};
        const headers = json(m.headers_json, {});
        const subject = m.subject || "";
        compose({
          account_id: m.account_id,
          to: action === "reply" ? recipient.email : "",
          subject:
            (action === "reply"
              ? /^re:/i.test(subject)
                ? ""
                : "Re: "
              : "Fwd: ") + subject,
          body_text: `\n\n----- ${action === "reply" ? "Original" : "Forwarded"} message -----\n${m.body_text || textFromHtml(m.body_html_safe)}`,
          in_reply_to:
            action === "reply" && m.internet_message_id
              ? `<${m.internet_message_id}>`
              : "",
          references:
            action === "reply"
              ? `${headers.references || ""} ${m.internet_message_id ? `<${m.internet_message_id}>` : ""}`.trim()
              : "",
        });
        return;
      }
      case "open-draft": {
        const draft = state.records.find((d) => +d.id === +id);
        if (draft)
          compose({
            ...json(draft.data_json, {}),
            account_id: draft.account_id,
            draft_id: draft.id,
            draft_version: draft.version,
          });
        return;
      }
      case "contact-mail": {
        const contact = state.data.contacts.find((c) => +c.id === +id);
        compose({ to: json(contact?.emails_json) });
        return;
      }
      case "move": {
        const message = state.selected;
        if (!message) return;
        const folders = await req(`accounts/${message.account_id}/folders`);
        dialog(
          "Move message",
          `<p>Choose Trash to remove this message from its current folder. The message is moved on the mail server.</p><label>Destination<select name="folder_id">${folders.map((f) => `<option value="${f.id}">${esc(f.display_name)}</option>`).join("")}</select></label>`,
          async (data, d, close) => {
            await req(`messages/${message.id}/move`, {
              method: "POST",
              data: { folder_id: +data.folder_id },
            });
            close();
            state.selected = null;
            await loadMessages();
            notice("Message moved. Destination synchronization is queued.");
          },
        );
        return;
      }
      case "attachment": {
        const file = await req(`attachments/${id}`);
        const bytes = Uint8Array.from(atob(file.content_base64), (c) =>
          c.charCodeAt(0),
        );
        const url = URL.createObjectURL(
          new Blob([bytes], { type: "application/octet-stream" }),
        );
        const link = document.createElement("a");
        link.href = url;
        link.download = file.filename;
        document.body.appendChild(link);
        link.click();
        link.remove();
        setTimeout(() => URL.revokeObjectURL(url), 10000);
        return;
      }
      case "delete-account": {
        if (
          !window.confirm(
            "Remove this account and all its cached mail, drafts, pending deliveries and shared access? Mail on the server will remain.",
          )
        )
          return;
        await req(`accounts/${id}`, { method: "DELETE" });
        await refreshData();
        if (+id === state.account) {
          state.account = +(state.data.accounts[0]?.id || 0);
          state.folder = 0;
          state.folders = [];
          state.messages = [];
          state.selected = null;
        }
        render();
        notice("Account removed.");
        return;
      }
      case "delete-contact":
      case "delete-draft":
      case "delete-resource":
      case "cancel-send": {
        if (
          !window.confirm(
            action === "cancel-send"
              ? "Cancel this queued delivery?"
              : "Delete this saved item?",
          )
        )
          return;
        const [type, item] =
          action === "delete-resource"
            ? id.split(":")
            : [
                action === "delete-contact"
                  ? "contacts"
                  : action === "delete-draft"
                    ? "drafts"
                    : "outbox",
                id,
              ];
        await req(`${type}/${item}`, { method: "DELETE" });
        await refreshData();
        await navigate(state.view);
        notice(
          action === "cancel-send" ? "Delivery cancelled." : "Item removed.",
        );
        return;
      }
      case "diagnostics": {
        const d = await req("diagnostics");
        const container = app.querySelector("#diag");
        if (!container) return;
        container.innerHTML = `<div class="cards">${Object.entries(d.stats)
          .map(
            ([k, v]) =>
              `<div class="metric"><b>${esc(v)}</b><span>${esc(k)}</span></div>`,
          )
          .join(
            "",
          )}</div><pre>${esc(JSON.stringify({ php: d.php, next_cron: d.cron ? new Date(d.cron * 1000).toLocaleString() : "Not scheduled", sodium: d.sodium, openssl: d.openssl, logs: d.logs }, null, 2))}</pre>`;
        return;
      }
    }
  }
  app.addEventListener("click", async (event) => {
    const target = event.target.closest("[data-action]");
    if (!target || target.disabled) return;
    const action = target.dataset.action,
      id = target.dataset.id;
    target.disabled = true;
    try {
      await act(action, id);
    } catch (error) {
      notice(error.message || "The request failed. Try again.", true);
    } finally {
      target.disabled = false;
    }
  });
  app.addEventListener("change", (event) => {
    if (event.target.id === "accountSelect") {
      clearTimeout(searchTimer);
      state.account = +event.target.value;
      state.folder = 0;
      state.folders = [];
      state.messages = [];
      state.selected = null;
      loadMailbox().catch((error) => notice(error.message, true));
    }
  });
  let searchTimer;
  app.addEventListener("input", (event) => {
    if (event.target.id === "mailSearch") {
      state.search = event.target.value;
      clearTimeout(searchTimer);
      state.sequence++;
      searchTimer = setTimeout(() => {
        if (state.view === "mail")
          loadMessages().catch((error) => notice(error.message, true));
      }, 300);
    }
  });
  window.addEventListener("beforeunload", (event) => {
    if (activeDialog) {
      event.preventDefault();
      event.returnValue = "";
    }
  });
  boot();
})();
