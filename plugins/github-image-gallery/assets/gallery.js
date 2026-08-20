/* GitHub Image Gallery -- 필터, 정렬, 라이트박스. 전부 브라우저에서 처리한다. */
(function () {
  'use strict';

  var TXT = (typeof GIG_I18N === 'object' && GIG_I18N) || {};
  var ALL = TXT.all || 'All groups';
  var SEL = TXT.selected || '%d selected';

  function init(root) {
    if (root.dataset.ready) return;
    root.dataset.ready = '1';

    var grid    = root.querySelector('.gig-grid');
    var items   = Array.prototype.slice.call(root.querySelectorAll('.gig-item'));
    var btn     = root.querySelector('.gig-drop-btn');
    var panel   = root.querySelector('.gig-drop');
    var label   = root.querySelector('.gig-drop-label');
    var sortSel = root.querySelector('.gig-sort');
    var query   = root.querySelector('.gig-q');
    var counter = root.querySelector('.gig-count');
    var empty   = root.querySelector('.gig-empty');
    if (!grid || !btn || !panel) return;

    var boxes = Array.prototype.slice.call(panel.querySelectorAll('input[type=checkbox]'));

    function chosen() {
      return boxes.filter(function (b) { return b.checked; })
                  .map(function (b) { return b.value; });
    }

    function apply() {
      var sel = chosen();
      var q = query ? (query.value || '').trim().toLowerCase() : '';
      var shown = 0;

      items.forEach(function (el) {
        var okGroup = (sel.length === 0) || sel.indexOf(el.dataset.group) !== -1;
        var okText = (q === '') || el.dataset.name.indexOf(q) !== -1;
        var ok = okGroup && okText;
        el.hidden = !ok;
        if (ok) shown++;
      });

      var mode = sortSel ? sortSel.value : 'name_asc';
      var frag = document.createDocumentFragment();
      items.slice().sort(function (a, b) {
        if (mode.indexOf('date') === 0) {
          var da = +a.dataset.date, db = +b.dataset.date;
          if (da !== db) return (mode === 'date_desc') ? db - da : da - db;
          return a.dataset.name.localeCompare(b.dataset.name);
        }
        var c = a.dataset.name.localeCompare(b.dataset.name);
        return (mode === 'name_desc') ? -c : c;
      }).forEach(function (el) { frag.appendChild(el); });
      grid.appendChild(frag);

      if (counter) counter.textContent = shown + ' / ' + items.length;
      if (empty) empty.hidden = shown !== 0;
      label.textContent = sel.length === 0 ? ALL : SEL.replace('%d', sel.length);
    }

    function open(on) {
      panel.hidden = !on;
      btn.setAttribute('aria-expanded', on ? 'true' : 'false');
    }

    btn.addEventListener('click', function (e) { e.stopPropagation(); open(panel.hidden); });
    panel.addEventListener('click', function (e) { e.stopPropagation(); });
    document.addEventListener('click', function () { open(false); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') open(false); });

    boxes.forEach(function (b) { b.addEventListener('change', apply); });
    Array.prototype.forEach.call(panel.querySelectorAll('.gig-mini'), function (m) {
      m.addEventListener('click', function () {
        var on = m.dataset.all === '1';
        boxes.forEach(function (b) { b.checked = on; });
        apply();
      });
    });
    if (sortSel) sortSel.addEventListener('change', apply);
    if (query) {
      var timer;
      query.addEventListener('input', function () {
        clearTimeout(timer);
        timer = setTimeout(apply, 120);
      });
    }

    if (root.dataset.lightbox === '1') {
      root.addEventListener('click', function (e) {
        if (e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
        var link = e.target.closest && e.target.closest('.gig-link');
        if (!link) return;
        e.preventDefault();

        var box = document.createElement('div');
        box.className = 'gig-box';
        box.setAttribute('role', 'dialog');
        box.setAttribute('aria-modal', 'true');

        var big = document.createElement('img');
        big.src = link.getAttribute('href');
        var thumb = link.querySelector('img');
        big.alt = thumb ? thumb.alt : '';

        var cap = document.createElement('div');
        cap.className = 'gig-cap';
        cap.textContent = link.closest('.gig-item').dataset.name;

        box.appendChild(big);
        box.appendChild(cap);
        document.body.appendChild(box);

        function close() {
          box.remove();
          document.removeEventListener('keydown', onKey);
        }
        function onKey(ev) { if (ev.key === 'Escape') close(); }
        box.addEventListener('click', close);
        document.addEventListener('keydown', onKey);
      });
    }

    apply();
  }

  function boot() {
    Array.prototype.forEach.call(document.querySelectorAll('.gig'), init);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
