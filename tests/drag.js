#!/usr/bin/env node
// Executes the real public/assets/app.js against a small DOM stub and drives the board's drag
// events.   node tests/drag.js
//
// This is here because the rest of the suite cannot see this class of bug. A drag-and-drop outline
// is a class toggled inside an event listener, and the panel used to keep a dashed border whenever a
// drag ended without a card landing in it. Nothing in flows.sh or auth.sh can catch that: they read
// server-rendered markup and never run a line of this file, and grepping the source cannot tell you
// whether a highlight is left behind when a drag ends in the wrong place. So the only honest test is
// to run the handlers and watch the class.
//
// The stub is deliberately the size of what this one section of app.js touches: closest, contains,
// classList, dataset, bubbling listeners and a dataTransfer. It is not a DOM, and the parts of app.js
// it does not model are not exercised by these checks.

const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const ROOT = path.resolve(__dirname, '..');
const APP = path.join(ROOT, 'public/assets/app.js');

let fails = 0;
const ok = (cond, name, detail = '') => {
  if (cond) console.log(`ok   ${name}`);
  else { console.log(`FAIL ${name}${detail ? ': ' + detail : ''}`); fails++; }
};

// ---- the smallest DOM that can run this file ---------------------------------

// A constructor rather than an object literal, so every node shares one prototype. Object literals
// each get their own, and methods bolted onto one of them leave every other node without them.
function Node(tag, opts = {}) {
  this.tag = tag;
  this.dataset = opts.dataset || {};
  // The DOM id is kept apart from data-* attributes on purpose: [data-id] matches the data-id
  // attribute and has nothing to do with id, and letting the two share a map made #board itself look
  // like a draggable card. Only what was asked for is present, so an absent attribute cannot match.
  this.id = opts.id || '';
  this.attrs = Object.assign({}, opts.attrs);
  this.children = [];
  this.parent = null;
  this.listeners = [];
  this.cls = new Set(opts.cls || []);
  const self = this;
  this.classList = {
    add: (...c) => c.forEach(x => self.cls.add(x)),
    remove: (...c) => c.forEach(x => self.cls.delete(x)),
    contains: c => self.cls.has(c),
    toggle: (c, on) => { on ? self.cls.add(c) : self.cls.delete(c); },
  };
  (opts.children || []).forEach(c => this.append(c));
}
Node.prototype.append = function (...kids) {
  kids.forEach(k => { k.parent = this; this.children.push(k); });
};
Node.prototype.contains = function (node) {
  for (let n = node; n; n = n.parent) if (n === this) return true;
  return false;
};
Node.prototype.closest = function (sel) {
  for (let n = this; n; n = n.parent) if (matches(n, sel)) return n;
  return null;
};
Node.prototype.querySelectorAll = function (sel) {
  return walk(this).filter(n => matches(n, sel));
};
Node.prototype.querySelector = function (sel) {
  return this.querySelectorAll(sel)[0] || null;
};
Node.prototype.addEventListener = function (type, fn) {
  this.listeners.push({ type, fn });
};

const mk = (tag, opts) => new Node(tag, opts);

// Attribute selectors, class selectors and tags, comma-separated. Only what this file asks for.
function matchesOne(node, sel) {
  return sel.split(/(?=[.[])/).filter(Boolean).every(tok => {
    if (tok.startsWith('.')) return node.cls.has(tok.slice(1));
    if (tok.startsWith('[')) {
      const m = /^\[(?:data-)?([\w-]+)(?:=["']?([^"'\]]*)["']?)?\]$/.exec(tok);
      if (!m) throw new Error('stub does not model selector ' + tok);
      const key = m[1], want = m[2];
      const have = key in node.dataset ? node.dataset[key]
                 : key in node.attrs ? node.attrs[key] : undefined;
      return want === undefined ? have !== undefined : String(have) === want;
    }
    return node.tag === tok;
  });
}
const matches = (node, sel) => sel.split(',').some(s => matchesOne(node, s));

function walk(node, out = []) {
  for (const c of node.children) { out.push(c); walk(c, out); }
  return out;
}

// Events bubble, which is what the handlers rely on: all five are registered on #board and read
// e.target to find the column.
function fire(type, target, extra = {}) {
  const ev = Object.assign({
    type, target, defaultPrevented: false,
    preventDefault() { this.defaultPrevented = true; },
  }, extra);
  for (let n = target; n; n = n.parent) {
    for (const l of n.listeners.filter(l => l.type === type)) l.fn.call(n, ev);
  }
  return ev;
}

// ---- the board under test --------------------------------------------------

const board = mk('main', { id: 'board', dataset: { board: '1' } });
const column = (status, ...cards) => {
  const section = mk('section', { dataset: { status } });
  const inner = mk('div', { cls: ['board-col'] });
  cards.forEach(c => inner.append(c));
  section.append(inner);
  board.append(section);
  return { section, inner };
};
const card = id => mk('a', { cls: ['card'], dataset: { id } });

const todo = column('todo', card('10'), card('11'));
const done = column('done', card('12'));

const drag = () => ({ store: {}, setData(k, v) { this.store[k] = v; }, getData(k) { return this.store[k] || ''; } });

const posted = [];
const document = {
  getElementById: id => walk(document.body).find(n => n.id === id) || null,
  querySelectorAll: sel => (sel === '.dragging' ? walk(document.body).filter(n => n.cls.has('dragging')) : []),
  createElement: tag => mk(tag),
  addEventListener() {},
  body: mk('body'),
};
document.body.append(board); // the board lives in the document, so .dragging is findable

// ---- run the shipped file --------------------------------------------------

const context = vm.createContext({
  document,
  htmx: { ajax: (method, url, opts) => posted.push({ method, url, opts }) },
  bootstrap: { Toast: function () { this.show = () => {}; } },
  // app.js also declares a <multi-select> custom element at top level. Evaluating that class needs
  // the two browser globals it rests on; define() does not construct one, so the drag section this
  // file actually exercises is untouched by them.
  HTMLElement: class {},
  customElements: { define() {} },
  console,
});
vm.runInContext(fs.readFileSync(APP, 'utf8'), context, { filename: APP });

const over = () => board.querySelectorAll('[data-status]').filter(c => c.cls.has('over')).map(c => c.dataset.status);
const cleared = name => ok(over().length === 0, name, 'still highlighted: ' + (over().join(',') || 'none'));

// A drag that gets as far as a drop, the way a real card move runs.
const dragCardOver = (from, overTarget) => {
  const dt = drag();
  fire('dragstart', from, { dataTransfer: dt });
  fire('dragover', overTarget, { dataTransfer: dt });
  return dt;
};

// ---- 1. a real drag highlights exactly one column, and takes it with it ---

let dt = dragCardOver(todo.inner.children[0], todo.inner.children[0]);
ok(over().join() === 'todo', 'dragging a card onto a panel highlights that panel', 'got ' + over());
ok(document.querySelectorAll('.dragging').length === 1, '  ...and marks the card being moved');

fire('dragover', done.inner, { dataTransfer: dt });
ok(over().join() === 'done', 'moving to another panel highlights that one', 'got ' + over());
ok(over().length === 1, '  ...and only that one', 'got ' + over().join(','));

// Moving within one panel fires dragleave on the way, between two children of the same column.
// Taking the highlight off there made the border blink as a card travelled down a column.
fire('dragleave', done.inner, { relatedTarget: done.inner.children[0] });
ok(over().join() === 'done', 'moving between children of one panel keeps the highlight', 'got ' + over());

const dropEv = fire('drop', done.inner, { dataTransfer: dt });
ok(dropEv.defaultPrevented, 'dropping on a panel is accepted');
  // The board page is served from /board/, so a URL not named from the app root resolves to the wrong
  // script -- board/move.php becomes /board/board/move.php. Comparing the string against what the
  // source happens to say would pass that; resolving it against the page is what a browser does.
  const PAGE = 'http://host/board/';
  const postedTo = posted.length === 1 && new URL(posted[0].url, PAGE).pathname;
  ok(posted.length === 1 && posted[0].method === 'PATCH' && postedTo === '/board/move.php',
     '  ...and patches the move to the right script', posted[0] && `${posted[0].method} ${posted[0].url} -> ${postedTo}`);
ok(posted[0] && posted[0].opts.values.id === '10'
   && posted[0].opts.values.status === 'done'
   && posted[0].opts.values.board === '1',
   '  ...with the card, the target panel and the board it came from');
fire('dragend', todo.inner.children[0]);
cleared('and nothing is left highlighted once the drag is over');
ok(document.querySelectorAll('.dragging').length === 0, 'and the card is no longer marked as moving');

// ---- 2. the bug: a drag that never had a card in it ------------------------

// Selecting text in a card, or a long press on a phone, starts a native drag the browser owns.
// dragstart fires on the board, but there is no card to pick up and nothing to deliver.
const before = posted.length;
const stray = drag();
fire('dragstart', todo.inner, { dataTransfer: stray });          // the column, not a card
const strayOver = fire('dragover', todo.inner, { dataTransfer: stray });
ok(over().length === 0, 'a drag with no card in it highlights nothing', 'got ' + over().join(','));
ok(!strayOver.defaultPrevented, '  ...and the panel is not a drop target for it');
fire('drop', todo.inner, { dataTransfer: stray });
fire('dragend', todo.inner);
cleared('so a tap on a panel leaves no outline behind');
ok(posted.length === before, 'and nothing was posted');
ok(document.querySelectorAll('.dragging').length === 0, 'and no card was left marked as moving');

// ---- 3. a drag that ends without landing on a panel -------------------------

dt = dragCardOver(todo.inner.children[0], todo.inner);
fire('dragleave', todo.inner, { relatedTarget: board });          // dragged out of the last panel
cleared('dragging off every panel clears the outline');

// Dropped in the gap between columns, or on the board itself: no drop handler matches, and no
// dragleave ever fired on the panel that was lit.
dt = dragCardOver(done.inner.children[0], done.inner);
fire('drop', board, { dataTransfer: dt });
fire('dragend', done.inner.children[0]);
cleared('dropping between panels clears the outline');
ok(posted.length === before, '  ...and moves nothing');

// Cancelled with Escape: dragend fires, drop never does.
dt = dragCardOver(todo.inner.children[0], todo.inner);
fire('dragend', todo.inner.children[0]);
cleared('cancelling a drag with Escape clears the outline');

// ---- 4. a file dragged in from the desktop ---------------------------------

const fileOver = fire('dragover', todo.inner, { dataTransfer: drag() });
ok(over().length === 0, 'a file dragged over the board highlights nothing', 'got ' + over().join(','));
ok(!fileOver.defaultPrevented, '  ...and is not a valid drop target either');

console.log(fails ? `\n${fails} check(s) failed` : '\nall drag checks passed');
process.exit(fails ? 1 : 0);
