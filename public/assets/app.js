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
// Grab the nearest element carrying data-id, rather than assuming the target is the card itself:
// the target is whatever was pointed at, which may be the title, a badge or an avatar. A subproject
// is the same single card as anything else, so there is no wrapper to mistake for the thing to move.
const dragCard = target => target.closest('[data-id]');
const board = document.getElementById('board');
if (board) {
  // Whether a card drag is really under way, as opposed to something else the browser decided was
  // draggable. A text selection, a long press on a phone and a file dragged in from the desktop all
  // fire dragover here as well, and the outline is the app saying "drop here", so it must not appear
  // for any of them. It used to be driven by hover alone: a panel kept the outline whenever a drag
  // ended without a card landing in it — a selection released over a panel, a drop in the gap between
  // columns, Escape — because only dragleave ever took it off, and none of those fire one. A tap on
  // a panel on a touch screen was enough to leave a dashed border on it.
  let dragging = false;
  const clearOver = () => board.querySelectorAll('[data-status]').forEach(c => c.classList.remove('over'));

  board.addEventListener('dragstart', e => {
    const c = dragCard(e.target);
    if (!c) return; // not a card: leave dragging false, so dragover highlights nothing
    e.dataTransfer.setData('text/plain', c.dataset.id);
    c.classList.add('dragging');
    dragging = true;
  });
  // dragend is the one event that fires however the drag ends, including a drag cancelled with
  // Escape and a drop that landed outside every column. Cleaning up here is what makes the outline
  // a property of a drag in progress rather than something left on the page afterwards.
  board.addEventListener('dragend', () => {
    dragging = false;
    document.querySelectorAll('.dragging').forEach(c => c.classList.remove('dragging'));
    clearOver();
  });
  board.addEventListener('dragover', e => {
    if (!dragging) return;
    const c = e.target.closest('[data-status]');
    if (!c) return;
    // preventDefault is what makes the column a valid drop target, so it belongs to a drag that has
    // something to deliver. Without this guard a file dragged in from the desktop was a valid drop
    // target too, and releasing it navigated the tab away from the board.
    e.preventDefault();
    // One card, one destination: clear the others here rather than trusting dragleave to have run
    // on the column being left, which is not guaranteed.
    clearOver();
    c.classList.add('over');
  });
  board.addEventListener('dragleave', e => {
    const c = e.target.closest('[data-status]');
    // Moving between two children of the same column fires this as well, and it used to take the
    // outline with it, so the border blinked on and off as a card travelled down a panel. Leaving
    // the column proper is the only thing that should clear it.
    if (c && !c.contains(e.relatedTarget)) c.classList.remove('over');
  });
  board.addEventListener('drop', e => {
    const col = e.target.closest('[data-status]'); if (!col) return;
    e.preventDefault();
    clearOver();
    const id = e.dataTransfer.getData('text/plain');
    if (id) htmx.ajax('POST', '/board/move.php', {target: '#board', swap: 'innerHTML',
                                                    values: {id, status: col.dataset.status, board: board.dataset.board}});
  });
}

// ---- header project search ----
// The menu under the search box is a plain positioned div, not a Bootstrap dropdown, so it inherits
// none of their dismiss behaviour and needs its own: Escape, and a click anywhere else, both close
// it. htmx re-shows it on the next keystroke, so hiding it here cannot strand it shut.
//
// "/" or Cmd/Ctrl+K focuses the box from anywhere, like the quick switcher it almost is.
// It never fires while typing in a field, so a "/" inside a comment stays a "/".
document.addEventListener('keydown', e => {
  if (e.key === 'Escape') document.querySelectorAll('.search-wrap .search-menu').forEach(m => m.classList.add('d-none'));
  const t = e.target && e.target.closest ? e.target.closest('input, textarea, select, [contenteditable="true"], [contenteditable=""]') : e.target;
  if (t) return;
  if (e.key === '/' && !e.ctrlKey && !e.metaKey && !e.altKey) {
    const box = document.querySelector('.search-wrap input[type="search"]');
    if (box) { e.preventDefault(); box.focus(); }
  } else if ((e.metaKey || e.ctrlKey) && !e.altKey && !e.shiftKey && (e.key === 'k' || e.key === 'K')) {
    const box = document.querySelector('.search-wrap input[type="search"]');
    if (box) { e.preventDefault(); box.focus(); }
  }
});
document.addEventListener('click', e => {
  if (e.target.closest('.search-wrap')) return;
  document.querySelectorAll('.search-wrap .search-menu').forEach(m => m.classList.add('d-none'));
});

// ---- error toasts ----
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
//
// The markup and the component are Bootstrap's, from its toast documentation: a .toast-container
// holding .toast elements, dismissed by the documented data-bs-dismiss="toast" button. The red is the
// documented color-scheme treatment too, rather than a stylesheet of ours, so there is no toast CSS in
// this project at all — if a rule is needed to make these look right, the class to reach for is
// Bootstrap's.
let slateToastBox = null;

// Built once, empty, on load rather than at the first failure. The docs are explicit that a live
// region created at the same moment as its content is generally not announced at all, so the region has
// to be in the document before anything is put in it.
function slateToastContainer() {
  if (slateToastBox) return slateToastBox;
  const box = document.createElement('div');
  // position-fixed rather than the docs' position-relative wrapper: same placement classes, but a
  // notification corner that scrolls away with the page stops being an interruption.
  box.className = 'toast-container position-fixed top-0 end-0 p-3';
  box.setAttribute('aria-live', 'polite');
  box.setAttribute('aria-atomic', 'true');
  document.body.append(box);
  slateToastBox = box;
  return box;
}

function slateToast(detail, headline) {
  const xhr = detail && detail.xhr;
  let ref = null;
  try { ref = xhr && xhr.getResponseHeader('X-Slate-Error'); } catch (e) { ref = null; }

  // The docs' basic form: a header and a body. The colour goes on the header rather than on the toast,
  // which is the whole reason for having one — text-bg-danger carries an !important, so on the toast it
  // would flood the body too, and .toast-header paints its own near-opaque background over whatever is
  // behind it. Putting the utility on the header alone is what leaves the body on Bootstrap's default.
  const el = document.createElement('div');
  el.className = 'toast';
  el.setAttribute('role', 'alert');
  el.setAttribute('aria-live', 'assertive');
  el.setAttribute('aria-atomic', 'true');

  // One generic headline, because the header is the part that is red and a stack of differently-worded
  // red bars is harder to read at a glance than one that says the same thing every time. What went
  // wrong goes in the body, where there is room to be specific.
  const title = document.createElement('strong');
  title.className = 'me-auto';
  title.textContent = 'An error occurred';
  const close = document.createElement('button');
  close.type = 'button';
  close.className = 'btn-close btn-close-white';
  close.dataset.bsDismiss = 'toast';
  close.setAttribute('aria-label', 'Close');
  const header = document.createElement('div');
  header.className = 'toast-header text-bg-danger';
  header.append(title, close);

  const body = document.createElement('div');
  body.className = 'toast-body';
  body.append(headline);
  // No reference at all means the request never reached the server, or the server failed before it
  // could answer one, so there is nothing to quote and nothing to look up afterwards.
  if (ref) {
    body.append(' Quote this reference: ');
    const code = document.createElement('code');
    code.textContent = ref;
    body.append(code);
  } else {
    body.append(' Nothing was saved.');
  }

  el.append(header, body);
  slateToastContainer().append(el);

  // autohide false, because the default is five seconds and an error that clears itself is gone before
  // anybody has looked up from what they were doing. The docs note that turning autohide off makes the
  // close button the only way out, which is the button above.
  const toast = new bootstrap.Toast(el, { autohide: false });
  // Bootstrap leaves a hidden toast sitting in the document, and these stack, so a dismissed one is
  // taken out rather than left holding a slot with nothing visible in it.
  el.addEventListener('hidden.bs.toast', () => el.remove());
  toast.show();
}

// Bubbled, because htmx dispatches these on the element that made the request and the pickers,
// buttons and board cards are all over the document. The names are taken from the pinned htmx build
// rather than from memory: htmx has renamed events across major versions, and a listener on an event
// that is never fired fails silently, which is the whole problem this section exists to fix.
document.body.addEventListener('htmx:responseError', e => slateToast(e.detail, 'That did not save.'));
document.body.addEventListener('htmx:sendError',   e => slateToast(e.detail, 'Could not reach the server.'));
document.body.addEventListener('htmx:timeout',     e => slateToast(e.detail, 'The server took too long.'));

class MultiSelect extends HTMLElement {
    static formAssociated = true;

    constructor() {
        super();

        this._internals = this.attachInternals();
        this._options = [];
    }

    connectedCallback() {
        this._options = [...this.querySelectorAll('option')];

        this._render();
        this._update();
    }

    _render() {
        const allLabel = this.getAttribute('all-label') ?? 'All';

        this.innerHTML = `
            <div class="dropdown">
                <button class="btn btn-outline-secondary dropdown-toggle"
                        type="button"
                        data-bs-toggle="dropdown"
                        data-bs-auto-close="outside">
                    ${allLabel}
                </button>

                <div class="dropdown-menu">
                    <label class="dropdown-item">
                        <input class="form-check-input me-2"
                               type="checkbox"
                               data-all>
                        ${allLabel}
                    </label>

                    <hr class="dropdown-divider">

                    ${this._options.map(option => `
                        <label class="dropdown-item">
                            <input class="form-check-input me-2"
                                   type="checkbox"
                                   data-option
                                   data-default="${option.dataset.default}"
                                   value="${this._escape(option.value)}"
                                   ${option.selected ? 'checked' : ''}>
                            ${this._escape(option.textContent)}
                        </label>
                    `).join('')}
                </div>
            </div>
        `;

        this._button = this.querySelector('button');
        this._all = this.querySelector('[data-all]');
        this._inputs = [...this.querySelectorAll('[data-option]')];

        this._all.addEventListener('change', () => {
            this._inputs.forEach(input => {
                input.checked = this._all.checked;
            });

            this._update();
        });

        this._inputs.forEach(input => {
            input.addEventListener('change', () => this._update());
        });
    }

    _update() {
        const selected = this._inputs.filter(input => input.checked);

        const allSelected = selected.length === this._inputs.length;

        this._all.checked = allSelected;

        if (allSelected) {
            this._button.textContent = this.getAttribute('all-label') ?? 'All';
        } else if (selected.length === 0) {
            this._button.textContent = 'None';
        } else if (selected.length === 1) {
            this._button.textContent = selected[0].parentElement.textContent.trim();
        } else {
            this._button.textContent = `${selected.length} selected`;
        }

        const useDefault = this._inputs.every(input => input.checked == (input.dataset.default === "true"));

        this._updateFormValue(selected, useDefault);
    }

    _updateFormValue(selected, useDefault) {
        if (!this.name || useDefault) {
            this._internals.setFormValue(null);
            return;
        }

        const formData = new FormData();

        for (const input of selected) {
            formData.append(this.name, input.value);
        }

        this._internals.setFormValue(formData);
    }

    get name() {
        return this.getAttribute('name');
    }

    _escape(value) {
        const div = document.createElement('div');
        div.textContent = value;
        return div.innerHTML;
    }
}

customElements.define('multi-select', MultiSelect);
