// ---- create-form pickers ----
// The only rows built in the browser, and only because there is nothing to build them from: a
// project being created is not saved until the form is posted, so no row ever comes back from the
// server to swap in. Every picker on a saved project is server-rendered and goes through htmx.
//
// This is scoped to [data-draft-picker], which only the create form carries, so it cannot reach a
// saved project's pickers even if it is loaded on the same page.

const DRAFT_ROW = 'd-flex align-items-center gap-2 border rounded bg-body-tertiary px-2 py-1 mb-1';

function wireDraftPicker(p) {
  const rows = p.querySelector('[data-draft-rows]');
  const modal = document.getElementById('draft-picker-' + p.dataset.draftPicker + '-modal');
  if (!rows || !modal) return;

  const list = modal.querySelector('[data-draft-options]');
  const search = modal.querySelector('[data-draft-search]');
  const none = modal.querySelector('[data-draft-none]');
  const field = p.dataset.draftField;

  // d-none means "filtered out" here and nothing else, so it can be toggled freely. Being chosen
  // is tracked separately, or clearing the search box would bring a picked entry back.
  const filter = () => {
    const q = search.value.trim().toLowerCase();
    let shown = 0;
    list.querySelectorAll('[data-draft-option]').forEach(o => {
      const hit = (!q || o.dataset.search.includes(q)) && !o.hasAttribute('data-draft-taken');
      o.classList.toggle('d-none', !hit);
      if (hit) shown++;
    });
    none.classList.toggle('d-none', shown > 0);
  };

  const option = value => list.querySelector(`[data-draft-option][data-value="${value}"]`);

  search.addEventListener('input', filter);
  modal.addEventListener('shown.bs.modal', () => { search.value = ''; filter(); search.focus(); });

  list.addEventListener('click', e => {
    const o = e.target.closest('[data-draft-option]');
    if (!o) return;
    const row = document.createElement('div');
    row.className = DRAFT_ROW;
    row.dataset.draftRow = '';
    row.dataset.value = o.dataset.value;
    const input = document.createElement('input');
    input.type = 'hidden';
    input.name = field;
    input.value = o.dataset.value;
    row.append(input);
    o.querySelectorAll('span').forEach(s => row.append(s.cloneNode(true)));
    const x = document.createElement('button');
    x.type = 'button';
    x.className = 'btn-close ms-auto';
    x.dataset.draftRemove = '';
    x.setAttribute('aria-label', 'Remove ' + row.querySelector('span').textContent.trim());
    row.append(x);
    rows.append(row);
    o.setAttribute('data-draft-taken', '');
    filter();
  });

  rows.addEventListener('click', e => {
    const x = e.target.closest('[data-draft-remove]');
    if (!x) return;
    const row = x.closest('[data-draft-row]');
    const back = option(row.dataset.value);
    row.remove();
    if (back) back.removeAttribute('data-draft-taken');
    filter();
  });
}

document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('[data-draft-picker]').forEach(wireDraftPicker);
});

// ---- saved picker dialogs ----
// A saved picker's search box filters through htmx, and asks the server again every time its
// dialog opens. Left as it was, that re-ask carries whatever was last typed, so reopening a
// dialog shows one person's filtered list and looks broken.
//
// Cleared on the way out rather than on the way in: the box emptying before the dialog opens means
// it cannot race the htmx trigger that re-asks on the same shown.bs.modal. Capture, because that
// event does not have to bubble for this to be worth doing.
document.addEventListener('hidden.bs.modal', e => {
  const search = e.target.querySelector && e.target.querySelector('input[type="search"]');
  if (search) search.value = '';
}, true);

// ---- board drag and drop ----
// Dropping a card posts the new status plus the board it came from, and htmx swaps the
// re-rendered board back in. Only board/ renders this, and it tags <main id="board"> with
// data-board.
//
// A subproject's card sits inside a wrapper that is also a .card, so grab the nearest element
// carrying data-id: grabbing the wrapper would start a drag with nothing to move.
const dragCard = target => target.closest('[data-id]');
const board = document.getElementById('board');
if (board) {
  board.addEventListener('dragstart', e => {
    const c = dragCard(e.target);
    if (c) { e.dataTransfer.setData('text/plain', c.dataset.id); c.classList.add('dragging'); }
  });
  board.addEventListener('dragend', () => document.querySelectorAll('.dragging').forEach(c => c.classList.remove('dragging')));
  board.addEventListener('dragover', e => { const c = e.target.closest('[data-status]'); if (c) { e.preventDefault(); c.classList.add('over'); } });
  board.addEventListener('dragleave', e => e.target.closest('[data-status]')?.classList.remove('over'));
  board.addEventListener('drop', e => {
    const col = e.target.closest('[data-status]'); if (!col) return;
    e.preventDefault();
    const id = e.dataTransfer.getData('text/plain');
    if (id) htmx.ajax('POST', 'board/move.php', {target: '#board', swap: 'innerHTML',
                                                     values: {id, status: col.dataset.status, board: board.dataset.board}});
  });
}

// ---- header project search ----
// The menu under the search box is a plain positioned div, not a Bootstrap dropdown, so it inherits
// none of their dismiss behaviour and needs its own: Escape, and a click anywhere else, both close
// it. htmx re-shows it on the next keystroke, so hiding it here cannot strand it shut.
document.addEventListener('keydown', e => {
  if (e.key === 'Escape') document.querySelectorAll('.search-wrap .search-menu').forEach(m => m.classList.add('d-none'));
});
document.addEventListener('click', e => {
  if (e.target.closest('.search-wrap')) return;
  document.querySelectorAll('.search-wrap .search-menu').forEach(m => m.classList.add('d-none'));
});

// ---- error toast ----
// htmx does not swap a response that is not 2xx; it fires htmx:responseError and leaves the page
// alone. That is the right default — a failed fragment must not replace good markup with half an
// error page — but it leaves a failure completely invisible. Nothing moves and nothing is said, which
// is how a server error in a background save reads as the app having ignored the click.
//
// These events are the only notice a request that went wrong will ever give, so they put one on
// screen. The reference code is read from a response header rather than the body, because the body is
// never swapped in and so cannot be read. It can only be in a header because the response is buffered
// far enough for the server still to be able to set one when it fails.
//
// Every string lands via textContent, never innerHTML: the message can carry a request path, and a
// path is whatever was typed into the URL.
const TOAST_MS = 9000;

function slateToast(detail, headline) {
  const xhr = detail && detail.xhr;
  let ref = null;
  try { ref = xhr && xhr.getResponseHeader('X-Slate-Error'); } catch (e) { ref = null; }

  const box = document.createElement('div');
  box.className = 'slate-toast alert alert-danger';
  box.setAttribute('role', 'alert');

  const title = document.createElement('strong');
  title.textContent = headline;
  const body = document.createElement('span');
  // No reference means the request never reached the server, or failed before it could answer.
  body.textContent = ref ? ' Quote this reference: ' + ref : ' Nothing was saved.';

  const close = document.createElement('button');
  close.type = 'button';
  close.className = 'btn-close';
  close.setAttribute('aria-label', 'Dismiss');
  close.addEventListener('click', () => box.remove());

  const text = document.createElement('div');
  text.className = 'me-auto';
  text.append(title, body);
  box.append(text, close);

  // One at a time. Two failures in a row are the same failure to the person clicking, and a column of
  // identical red panels is its own way of saying nothing.
  document.querySelectorAll('.slate-toast').forEach(t => t.remove());
  document.body.append(box);
  setTimeout(() => box.remove(), TOAST_MS);
}

// Bubbled, because htmx dispatches these on the element that made the request and the pickers,
// buttons and board cards are all over the document. The names are taken from the pinned htmx build
// rather than from memory: htmx has renamed events across major versions, and a listener on an event
// that is never fired fails silently, which is the whole problem this section exists to fix.
document.body.addEventListener('htmx:responseError', e => slateToast(e.detail, 'That did not save.'));
document.body.addEventListener('htmx:sendError',   e => slateToast(e.detail, 'Could not reach the server.'));
document.body.addEventListener('htmx:timeout',     e => slateToast(e.detail, 'The server took too long.'));
