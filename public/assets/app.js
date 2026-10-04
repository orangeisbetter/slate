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
