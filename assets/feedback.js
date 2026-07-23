/**
 * Rückmeldung-Werkzeug (Frontend).
 *
 * Zwei Betriebsarten:
 *  – Normal: der Kunde in der Vorschau setzt Kommentare und Markierungen.
 *  – Zurückspielen (CFG.replay): der Betreiber sieht eine gespeicherte
 *    Rückmeldung read-only über der echten Seite, an den verankerten Stellen.
 *
 * Verankerung: Kommentare merken sich das getroffene Seitenelement plus einen
 * relativen Versatz, nicht nackte Pixel. So sitzt ein Pin auch bei anderer
 * Fensterbreite an der gemeinten Stelle. Zeichnungen liegen relativ zur
 * Dokumentbreite; die Breite beim Erstellen wird mitgespeichert.
 *
 * Touch: im Kommentar- und Ansehen-Modus scrollt ein Wisch normal (touch-action
 * pan-y); ein Kommentar entsteht nur beim Tippen ohne Wischen. In den Zeichen-
 * modi fängt der Finger, dafür scrollt man über „Ansehen“.
 *
 * Zwischenspeicher: die laufende Sitzung liegt im localStorage und übersteht ein
 * versehentliches Neuladen. Nach dem Absenden wird sie gelöscht.
 */
(function () {
  "use strict";
  if (!window.MHF) return;
  const CFG = window.MHF, T = CFG.i18n, SVGNS = 'http://www.w3.org/2000/svg';
  const REPLAY = ( CFG.mode === 'show' && CFG.show && CFG.show.items && CFG.show.items.length ) ? CFG.show : null;

  const root = document.createElement('div');
  root.className = 'avfb';
  root.innerHTML = '<div id="avfb-layer"><svg id="avfb-ink" xmlns="' + SVGNS + '"></svg></div>';
  document.body.appendChild(root);
  const layer = root.querySelector('#avfb-layer');
  const svg = root.querySelector('#avfb-ink');

  let bar = null, tool = 'view', seq = 0, sent = false;
  const items = [], redo = [];
  const replayPins = [];

  function sizeLayer() {
    /*
     * Erst auf null setzen: Die Ebene liegt selbst im Dokument und würde sonst
     * in die eigene Messung einfließen. Ohne diesen Schritt wächst sie bei
     * jeder Messung ein Stück – sichtbar als leerer Raum unter dem Fußbereich.
     */
    layer.style.height = '0px';
    svg.style.height = '0px';

    const h = Math.max(
      document.body.scrollHeight,
      document.body.offsetHeight,
      document.documentElement.offsetHeight
    );

    layer.style.height = h + 'px';
    svg.style.height = h + 'px';
    svg.setAttribute('viewBox', '0 0 ' + window.innerWidth + ' ' + h);
    if (REPLAY) place();
  }
  window.addEventListener('resize', sizeLayer);
  window.addEventListener('load', sizeLayer);
  sizeLayer();

  /* Schutz vor versehentlichem Verlassen: Solange das Werkzeug offen ist und es
     ungesendete Anmerkungen gibt, fragt der Browser vor dem Seitenwechsel /
     Neuladen / Schließen nach. Erst nach „Abbrechen“ oder „Absenden“ ist der
     Weg frei. (Browser erlauben nur diesen Bestätigungsdialog, kein hartes
     Sperren – mehr ist technisch nicht möglich.) */
  window.addEventListener('beforeunload', function (e) {
    if (bar && items.length && !sent) {
      e.preventDefault();
      e.returnValue = '';
      return '';
    }
  });

  function docPt(e) { const r = layer.getBoundingClientRect(); return { x: e.clientX - r.left, y: e.clientY - r.top }; }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c])); }
  function round(n) { return Math.round(n * 1000) / 1000; }

  /* ---------- Verankerung ---------- */
  function anchorFor(cx, cy) {
    const prev = layer.style.pointerEvents;
    layer.style.pointerEvents = 'none';
    const el = document.elementFromPoint(cx, cy);
    layer.style.pointerEvents = prev;
    if (!el || el === document.body || el === document.documentElement) return { label: '—', selector: 'body', ax: 0, ay: 0 };
    const r = el.getBoundingClientRect();
    return {
      label: ((el.textContent || '').trim().replace(/\s+/g, ' ').slice(0, 60)) || el.tagName.toLowerCase(),
      selector: cssPath(el),
      ax: round(r.width ? (cx - r.left) / r.width : 0),
      ay: round(r.height ? (cy - r.top) / r.height : 0)
    };
  }
  function cssPath(el) {
    if (el.id) return '#' + el.id;
    const parts = []; let n = el;
    while (n && n.nodeType === 1 && parts.length < 6 && n !== document.body) {
      let s = n.tagName.toLowerCase(), p = n.parentElement;
      if (p) { const same = [...p.children].filter(c => c.tagName === n.tagName); if (same.length > 1) s += ':nth-of-type(' + (same.indexOf(n) + 1) + ')'; }
      parts.unshift(s);
      if (n.id) { parts[0] = '#' + n.id; break; }
      n = p;
    }
    return parts.join(' > ');
  }
  function resolveAnchor(rec) {
    let el = null;
    try { el = rec.selector ? document.querySelector(rec.selector) : null; } catch (e) {}
    const lr = layer.getBoundingClientRect();
    if (el) { const r = el.getBoundingClientRect(); return { x: r.left - lr.left + rec.ax * r.width, y: r.top - lr.top + rec.ay * r.height }; }
    return { x: rec.x || 0, y: rec.y || 0 };
  }

  function mk(tag, attrs) { const el = document.createElementNS(SVGNS, tag); for (const k in attrs) el.setAttribute(k, attrs[k]); svg.appendChild(el); return el; }

  /* ---------- Zettel im Zurückspiel-Modus ---------- */
  function closeAllPins() {
    layer.querySelectorAll('.avfb-pin.avfb-open').forEach(p => {
      p.classList.remove('avfb-open', 'avfb-pop--unten');
    });
  }

  // Kein Platz nach oben? Dann klappt der Zettel nach unten auf.
  function platziereZettel(el) {
    const pop = el.querySelector('.avfb-pop--ro');
    if (!pop) return;
    el.classList.remove('avfb-pop--unten');
    const r = pop.getBoundingClientRect();
    if (r.top < 56) el.classList.add('avfb-pop--unten');
  }

  /* ---------- Pin rendern ---------- */
  function renderPin(rec, replay, openNow) {
    const el = document.createElement('div');
    el.className = 'avfb-pin';
    el.style.left = rec.x + 'px'; el.style.top = rec.y + 'px';
    el.innerHTML = '<div class="avfb-dot"><span class="avfb-num">' + rec.n + '</span></div>' +
      (replay
        ? '<div class="avfb-pop avfb-pop--ro">' + (rec.text ? esc(rec.text) : '<em>' + esc(T.noText) + '</em>') + '</div>'
        : '<div class="avfb-pop"><textarea placeholder="' + esc(T.placeholder) + '"></textarea><div class="avfb-poprow"><button class="avfb-del">' + esc(T.delete) + '</button><button class="avfb-ok">' + esc(T.apply) + '</button></div></div>');
    layer.appendChild(el);

    // Zurückspiel-Modus: Zettel steckt im Pin und klappt erst auf Klick auf.
    if (replay) {
      el.querySelector('.avfb-dot').addEventListener('click', ev => {
        ev.preventDefault(); ev.stopPropagation();
        const warOffen = el.classList.contains('avfb-open');
        closeAllPins();
        if (!warOffen) { el.classList.add('avfb-open'); platziereZettel(el); }
      });
      return el;
    }

    const dot = el.querySelector('.avfb-dot'), pop = el.querySelector('.avfb-pop'), ta = el.querySelector('textarea');
    let open = !!openNow; show(open);
    function show(v) { pop.style.display = v ? 'block' : 'none'; if (v) { ta.value = rec.text; setTimeout(() => ta.focus(), 0); } }

    let dragging = false, moved = false, sx = 0, sy = 0, ox = 0, oy = 0;
    dot.addEventListener('pointerdown', ev => { ev.stopPropagation(); dragging = true; moved = false; sx = ev.clientX; sy = ev.clientY; ox = rec.x; oy = rec.y; dot.setPointerCapture(ev.pointerId); });
    dot.addEventListener('pointermove', ev => {
      if (!dragging) return;
      const dx = ev.clientX - sx, dy = ev.clientY - sy;
      if (Math.hypot(dx, dy) > 4) moved = true;
      if (moved) { rec.x = ox + dx; rec.y = oy + dy; el.style.left = rec.x + 'px'; el.style.top = rec.y + 'px'; }
    });
    dot.addEventListener('pointerup', ev => {
      if (!dragging) return; dragging = false;
      if (moved) { const a = anchorFor(ev.clientX, ev.clientY); rec.anchor = a.label; rec.selector = a.selector; rec.ax = a.ax; rec.ay = a.ay; save(); }
      else { open = !open; show(open); }
    });
    el.querySelector('.avfb-ok').addEventListener('click', ev => { ev.stopPropagation(); rec.text = ta.value.trim(); open = false; show(false); save(); });
    el.querySelector('.avfb-del').addEventListener('click', ev => { ev.stopPropagation(); removeItem(rec); });
    ta.addEventListener('click', ev => ev.stopPropagation());
    ta.addEventListener('pointerdown', ev => ev.stopPropagation());
    return el;
  }

  /* ---------- Zeichnung aus Daten ---------- */
  function buildDraw(d) {
    const scale = d.w ? window.innerWidth / d.w : 1;
    if (d.type === 'pen') return mk('path', { d: d.path, fill: 'none', stroke: 'var(--mhf-mark, #d8402f)', 'stroke-width': 3, 'stroke-linecap': 'round', 'stroke-linejoin': 'round' });
    if (d.type === 'circle') { const m = (d.path || '').replace('e ', '').split(','); if (m.length < 4) return null; return mk('ellipse', { cx: m[0] * scale, cy: m[1], rx: m[2] * scale, ry: m[3], fill: 'none', stroke: 'var(--mhf-mark, #d8402f)', 'stroke-width': 3 }); }
    if (d.type === 'line') { const m = (d.path || '').replace('l ', '').split(','); if (m.length < 4) return null; return mk('line', { x1: m[0] * scale, y1: m[1], x2: m[2] * scale, y2: m[3], stroke: 'var(--mhf-mark, #d8402f)', 'stroke-width': 3, 'stroke-linecap': 'round' }); }
    return null;
  }

  function buildAll(list, replay) {
    list.forEach(d => {
      if (d.type === 'pin') {
        const rec = Object.assign({ type: 'pin' }, d);
        const pos = resolveAnchor(rec); rec.x = pos.x; rec.y = pos.y;
        rec.el = renderPin(rec, replay, false);
        if (replay) replayPins.push(rec); else items.push(rec);
      } else {
        const el = buildDraw(d);
        if (el) { const rec = { type: d.type, el, w: d.w }; if (!replay) items.push(rec); }
      }
    });
  }
  function place() { replayPins.forEach(rec => { const pos = resolveAnchor(rec); rec.el.style.left = pos.x + 'px'; rec.el.style.top = pos.y + 'px'; }); }

  /* =================== ZURÜCKSPIELEN =================== */
  if (REPLAY) {
    layer.style.pointerEvents = 'none';
    const b = document.createElement('div');
    b.className = 'avfb-replay-bar';
    b.innerHTML = '<span>' + esc(REPLAY.title || T.reviewHead) + '</span><a href="' + esc(REPLAY.back) + '">' + esc(T.reviewBack) + ' ✕</a>';
    root.appendChild(b);
    buildAll(REPLAY.items, true);
    place();
    document.addEventListener('click', () => closeAllPins());
    document.addEventListener('keydown', ev => { if (ev.key === 'Escape') closeAllPins(); });
    window.addEventListener('resize', () => {
      place();
      const offen = layer.querySelector('.avfb-pin.avfb-open');
      if (offen) platziereZettel(offen);
    });
    return;
  }

  /* =================== NORMAL-MODUS =================== */
  const startBtn = document.createElement('button');
  startBtn.className = 'avfb-start'; startBtn.id = 'avfb-start';
  startBtn.innerHTML = '<svg viewBox="0 0 24 24"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2Z"/></svg>' + esc(T.start);
  root.appendChild(startBtn);

  /* --- Kurze Erklärung der Werkzeuge ---------------------------------- */

  const HILFE_MERKER = 'mhfHilfeAus';

  function hilfeAus() {
    try { return window.localStorage.getItem(HILFE_MERKER) === '1'; } catch (e) { return false; }
  }

  let tipSeen = false;

  startBtn.addEventListener('click', () => {
    startBtn.style.display = 'none';
    if (!tipSeen && !hilfeAus()) { showTip(); } else { openBar(); }
  });

  /** Erklärt in einem Satz je Werkzeug, was es tut. */
  function showTip(nurAnsehen) {
    tipSeen = true;

    const zeilen = [
      [ico.pin, T.comment, T.helpPin],
      [ico.pen, T.pen, T.helpPen],
      [ico.circle, T.circle, T.helpCircle],
      [ico.line, T.underline, T.helpLine],
      [ico.undo, T.undo, T.helpUndo]
    ].map(z => '<li>' + z[0] + '<span><b>' + esc(z[1]) + '</b> ' + esc(z[2]) + '</span></li>').join('');

    const tip = document.createElement('div');
    tip.className = 'avfb-tip avfb-tip--hilfe';
    tip.innerHTML =
      '<b>' + esc(T.helpHead) + '</b>' +
      '<p class="avfb-tiplead">' + esc(T.hint) + '</p>' +
      '<ul class="avfb-hilfe">' + zeilen + '</ul>' +
      '<p class="avfb-tipfoot">' + esc(T.helpDone) + '</p>' +
      '<div class="avfb-tipbtns">' +
        '<button type="button" id="avfb-tip-ok">' + esc(T.gotit) + '</button>' +
        (nurAnsehen ? '' : '<button type="button" class="avfb-tip-skip" id="avfb-tip-skip">' + esc(T.helpSkip) + '</button>') +
      '</div>';
    root.appendChild(tip);

    tip.querySelector('#avfb-tip-ok').addEventListener('click', () => {
      tip.remove();
      if (!nurAnsehen) openBar();
    });

    const skip = tip.querySelector('#avfb-tip-skip');
    if (skip) {
      skip.addEventListener('click', () => {
        try { window.localStorage.setItem(HILFE_MERKER, '1'); } catch (e) {}
        tip.remove();
        openBar();
      });
    }
  }

  function openBar() {
    if (bar) { bar.classList.add('avfb-in'); return; }
    bar = document.createElement('div');
    bar.className = 'avfb-bar'; bar.setAttribute('role', 'toolbar');
    bar.innerHTML =
      grp([tb('view', ico.view, T.view, true), tb('pin', ico.pin, T.comment), tb('pen', ico.pen, T.pen), tb('circle', ico.circle, T.circle), tb('line', ico.line, T.underline)]) +
      '<div class="avfb-sep"></div>' +
      grp(['<button class="avfb-tool" id="avfb-undo" disabled>' + ico.undo + '<span class="avfb-lbl">' + esc(T.undo) + '</span></button>',
        '<button class="avfb-tool" id="avfb-redo" disabled>' + ico.redo + '<span class="avfb-lbl">' + esc(T.redo) + '</span></button>',
        '<span class="avfb-count" id="avfb-count">' + items.length + '</span>']) +
      '<button class="avfb-tool avfb-help" id="avfb-help" title="' + esc(T.helpHead) + '"><svg width="16" height="16" viewBox="0 0 24 24"><path d="M9.2 9a2.8 2.8 0 1 1 3.6 2.7c-.8.3-1.3 1-1.3 1.9v.4"/><circle cx="11.9" cy="17.6" r="1"/></svg><span class="avfb-lbl">?</span></button>' +
      '<button class="avfb-cancel" id="avfb-cancel" title="' + esc(T.cancel) + '">' +
        '<svg width="16" height="16" viewBox="0 0 24 24"><path d="M6 6l12 12M18 6 6 18"/></svg>' +
        '<span class="avfb-lbl">' + esc(T.cancel) + '</span></button>' +
      '<button class="avfb-done" id="avfb-done"><svg width="18" height="18" viewBox="0 0 24 24"><path d="m5 12 5 5L20 7"/></svg>' + esc(T.done) + '</button>';
    root.appendChild(bar);
    requestAnimationFrame(() => bar.classList.add('avfb-in'));
    bar.querySelectorAll('.avfb-tool[data-tool]').forEach(b => b.addEventListener('click', () => setTool(b.dataset.tool)));
    bar.querySelector('#avfb-undo').addEventListener('click', undo);
    bar.querySelector('#avfb-redo').addEventListener('click', redoFn);
    bar.querySelector('#avfb-done').addEventListener('click', openReview);
    bar.querySelector('#avfb-cancel').addEventListener('click', abbrechen);
    bar.querySelector('#avfb-help').addEventListener('click', () => showTip(true));
    setTool('view'); sync();
  }
  /**
   * Abbrechen: alle Anmerkungen verwerfen und das Werkzeug schließen.
   * Bei vorhandenen Anmerkungen wird vorher nachgefragt – sonst wäre die
   * Arbeit mit einem Fehlklick weg.
   */
  function abbrechen() {
    if (items.length && !window.confirm(T.cancelAsk)) return;
    items.slice().forEach(r => { if (r.el && r.el.parentNode) r.el.parentNode.removeChild(r.el); });
    items.length = 0; redo.length = 0;
    clearDraft();
    if (bar) { bar.remove(); bar = null; }
    const rv = root.querySelector('.avfb-review'); if (rv) rv.remove();
    setTool('view');
    layer.classList.remove('avfb-armed');
    startBtn.style.display = '';
    startBtn.classList.remove('avfb-has-draft');
  }

  function setTool(t) {
    tool = t;
    if (bar) bar.querySelectorAll('.avfb-tool[data-tool]').forEach(x => x.setAttribute('aria-pressed', x.dataset.tool === t ? 'true' : 'false'));
    layer.classList.toggle('avfb-armed', t !== 'view');
    layer.style.touchAction = (t === 'pen' || t === 'circle' || t === 'line') ? 'none' : 'pan-y';
  }
  function grp(a) { return '<div style="display:flex;gap:4px;align-items:center">' + a.join('') + '</div>'; }
  function tb(id, i, l, on) { return '<button class="avfb-tool" data-tool="' + id + '" aria-pressed="' + (on ? 'true' : 'false') + '">' + i + '<span class="avfb-lbl">' + esc(l) + '</span></button>'; }

  function addPin(p, cx, cy) {
    seq++;
    const a = anchorFor(cx, cy);
    const rec = { type: 'pin', n: seq, x: p.x, y: p.y, text: '', anchor: a.label, selector: a.selector, ax: a.ax, ay: a.ay };
    rec.el = renderPin(rec, false, true);
    push(rec);
  }

  let draw = null, downPt = null;
  layer.addEventListener('pointerdown', e => {
    if (tool === 'view' || (e.target.closest && e.target.closest('.avfb-pin'))) return;
    downPt = { x: e.clientX, y: e.clientY };
    const p = docPt(e);
    if (tool === 'pin') return;
    layer.setPointerCapture(e.pointerId);
    if (tool === 'pen') draw = { type: 'pen', el: mk('path', { d: 'M ' + p.x + ' ' + p.y, fill: 'none', stroke: 'var(--mhf-mark, #d8402f)', 'stroke-width': 3, 'stroke-linecap': 'round', 'stroke-linejoin': 'round' }), pts: [p] };
    else if (tool === 'circle') draw = { type: 'circle', el: mk('ellipse', { fill: 'none', stroke: 'var(--mhf-mark, #d8402f)', 'stroke-width': 3 }), x0: p.x, y0: p.y };
    else if (tool === 'line') draw = { type: 'line', el: mk('line', { stroke: 'var(--mhf-mark, #d8402f)', 'stroke-width': 3, 'stroke-linecap': 'round', x1: p.x, y1: p.y, x2: p.x, y2: p.y }), x0: p.x, y0: p.y };
  });
  layer.addEventListener('pointermove', e => {
    if (!draw) return; const p = docPt(e);
    if (draw.type === 'pen') { draw.pts.push(p); draw.el.setAttribute('d', draw.el.getAttribute('d') + ' L ' + p.x + ' ' + p.y); }
    else if (draw.type === 'circle') { draw.el.setAttribute('cx', (draw.x0 + p.x) / 2); draw.el.setAttribute('cy', (draw.y0 + p.y) / 2); draw.el.setAttribute('rx', Math.abs(p.x - draw.x0) / 2); draw.el.setAttribute('ry', Math.abs(p.y - draw.y0) / 2); }
    else if (draw.type === 'line') { draw.el.setAttribute('x2', p.x); draw.el.setAttribute('y2', p.y); }
  });
  layer.addEventListener('pointerup', e => {
    if (tool === 'pin' && downPt) { if (Math.hypot(e.clientX - downPt.x, e.clientY - downPt.y) < 8) addPin(docPt(e), e.clientX, e.clientY); downPt = null; return; }
    endDraw();
  });
  layer.addEventListener('pointercancel', endDraw);
  function endDraw() {
    if (!draw) return;
    const el = draw.el, tiny =
      (draw.type === 'pen' && draw.pts.length < 3) ||
      (draw.type === 'circle' && +el.getAttribute('rx') < 6 && +el.getAttribute('ry') < 6) ||
      (draw.type === 'line' && Math.hypot(el.getAttribute('x2') - el.getAttribute('x1'), el.getAttribute('y2') - el.getAttribute('y1')) < 10);
    if (tiny) { el.remove(); draw = null; return; }
    push({ type: draw.type, el, w: window.innerWidth }); draw = null;
  }

  function push(rec) { items.push(rec); redo.length = 0; sync(); save(); }
  function removeItem(rec) { const i = items.indexOf(rec); if (i > -1) items.splice(i, 1); rec.el.remove(); sync(); save(); }
  function undo() { const r = items.pop(); if (!r) return; r.el.remove(); redo.push(r); sync(); save(); }
  function redoFn() { const r = redo.pop(); if (!r) return; (r.type === 'pin' ? layer : svg).appendChild(r.el); items.push(r); sync(); save(); }
  function sync() { if (!bar) return; bar.querySelector('#avfb-count').textContent = items.length; bar.querySelector('#avfb-undo').disabled = !items.length; bar.querySelector('#avfb-redo').disabled = !redo.length; }

  const DKEY = 'mhf_draft::' + location.pathname;
  function save() { try { localStorage.setItem(DKEY, JSON.stringify({ t: Date.now(), seq, items: items.map(serialize) })); } catch (e) {} }
  function clearDraft() { try { localStorage.removeItem(DKEY); } catch (e) {} }
  function restore() {
    let raw; try { raw = localStorage.getItem(DKEY); } catch (e) { return; }
    if (!raw) return;
    let data; try { data = JSON.parse(raw); } catch (e) { return; }
    if (!data || !Array.isArray(data.items) || !data.items.length) return;
    if (Date.now() - (data.t || 0) > 7 * 864e5) { clearDraft(); return; }
    seq = data.seq || 0;
    buildAll(data.items, false);
    startBtn.classList.add('avfb-has-draft');
  }

  function openReview() {
    const pins = items.filter(i => i.type === 'pin'), marks = items.filter(i => i.type !== 'pin');
    let list = '';
    pins.forEach(c => { list += '<div class="avfb-item"><div class="avfb-badge">' + c.n + '</div><div><div class="avfb-itxt">' + (c.text ? esc(c.text) : '<span class="avfb-empty">' + esc(T.noText) + '</span>') + '</div><div class="avfb-imeta">' + esc(c.anchor) + '</div></div></div>'; });
    if (marks.length) list += '<div class="avfb-item"><div class="avfb-badge">✎</div><div><div class="avfb-itxt">' + esc(T.marks) + '</div><div class="avfb-imeta">' + marks.length + '×</div></div></div>';
    if (!list) list = '<p class="avfb-empty">' + esc(T.empty) + '</p>';
    const rv = document.createElement('div');
    rv.className = 'avfb-review avfb-open';
    rv.innerHTML = '<div class="avfb-box"><p class="avfb-kick">' + esc(T.reviewKick) + '</p><h2 class="avfb-h">' + esc(T.reviewHead) + '</h2><input class="avfb-name" id="avfb-name" placeholder="' + esc(T.namePrompt) + '" maxlength="80"><div id="avfb-list">' + list + '</div><div class="avfb-foot"><button class="avfb-ghost avfb-drop" id="avfb-drop">' + esc(T.cancel) + '</button><button class="avfb-ghost" id="avfb-back">' + esc(T.reviewBack) + '</button><button class="avfb-solid" id="avfb-send"' + (items.length ? '' : ' disabled') + '>' + esc(T.send) + '</button></div></div>';
    root.appendChild(rv);
    rv.querySelector('#avfb-back').addEventListener('click', () => rv.remove());
    rv.querySelector('#avfb-drop').addEventListener('click', () => { rv.remove(); abbrechen(); });
    rv.querySelector('#avfb-send').addEventListener('click', () => submit(rv));
  }
  function submit(rv) {
    const btn = rv.querySelector('#avfb-send'); btn.disabled = true; btn.textContent = T.sending;
    const body = new FormData();
    body.append('action', 'mhf_submit'); body.append('nonce', CFG.nonce || '');
    // Zugangsschlüssel mitschicken: dadurch klappt das Senden auch dann, wenn
    // die Seite aus dem Zwischenspeicher kam und kein Cookie gesetzt wurde.
    var pass = CFG.pass || '';
    if (!pass) { try { pass = window.localStorage.getItem('mhfPass') || ''; } catch (e) {} }
    if (pass) { body.append('pass', pass); try { window.localStorage.setItem('mhfPass', pass); } catch (e) {} }
    body.append('payload', JSON.stringify({ name: (rv.querySelector('#avfb-name') || {}).value || '', page: CFG.page, items: items.map(serialize) }));
    fetch(CFG.ajax, { method: 'POST', body, credentials: 'same-origin' }).then(r => r.json()).then(res => {
      if (!res || !res.success) throw 0;
      sent = true; // Weg ist frei – kein Verlassen-Warnhinweis mehr.
      clearDraft();
      rv.querySelector('.avfb-box').innerHTML = '<p class="avfb-kick">' + esc(T.reviewKick) + '</p><h2 class="avfb-h">' + esc(T.thanks) + '</h2><p style="color:var(--on-dark,#a8a29a);line-height:1.6">' + esc(T.thanksSub) + '</p>';
    }).catch(() => { btn.disabled = false; btn.textContent = T.send; alert(T.failed); });
  }
  function serialize(r) {
    if (r.type === 'pin') return { type: 'pin', n: r.n, text: r.text, anchor: r.anchor, selector: r.selector, ax: r.ax, ay: r.ay, x: r.x, y: r.y };
    if (r.type === 'pen') return { type: 'pen', path: r.el.getAttribute('d'), w: r.w };
    if (r.type === 'circle') return { type: 'circle', path: 'e ' + r.el.getAttribute('cx') + ',' + r.el.getAttribute('cy') + ',' + r.el.getAttribute('rx') + ',' + r.el.getAttribute('ry'), w: r.w };
    if (r.type === 'line') return { type: 'line', path: 'l ' + r.el.getAttribute('x1') + ',' + r.el.getAttribute('y1') + ',' + r.el.getAttribute('x2') + ',' + r.el.getAttribute('y2'), w: r.w };
    return { type: r.type };
  }

  const ico = {
    view: '<svg viewBox="0 0 24 24"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg>',
    pin: '<svg viewBox="0 0 24 24"><path d="M12 21s7-6.3 7-11a7 7 0 1 0-14 0c0 4.7 7 11 7 11Z"/><circle cx="12" cy="10" r="2.4"/></svg>',
    pen: '<svg viewBox="0 0 24 24"><path d="M4 20s2-1 4-3 9-9 9-9l-1-1s-7 7-9 9-3 4-3 4Z"/><path d="M15 6l3 3"/></svg>',
    circle: '<svg viewBox="0 0 24 24"><ellipse cx="12" cy="12" rx="9" ry="6.5"/></svg>',
    line: '<svg viewBox="0 0 24 24"><path d="M3 17c4 0 5-2 9-2s5 2 9 2"/></svg>',
    undo: '<svg viewBox="0 0 24 24"><path d="M9 7 4 12l5 5"/><path d="M4 12h11a5 5 0 0 1 0 10h-1"/></svg>',
    redo: '<svg viewBox="0 0 24 24"><path d="m15 7 5 5-5 5"/><path d="M20 12H9a5 5 0 0 0 0 10h1"/></svg>'
  };

  restore();
})();
