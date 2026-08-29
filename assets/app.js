/* Модул „Анализи на МО“ – без библиотеки. */
(function () {
  'use strict';

  var form = document.getElementById('entryForm');
  if (!form) return;

  var rowsBox = document.getElementById('rows');
  var tpl = document.getElementById('rowTpl');
  var STATES = ['', 'mastered', 'not_mastered'];   // цикъл при щракане

  /* ---------- помощни ---------- */
  function rowIndex(card) { return parseInt(card.getAttribute('data-row'), 10); }

  function renumber() {
    Array.prototype.forEach.call(rowsBox.children, function (card, i) {
      var label = card.querySelector('.rn');
      if (label) label.textContent = i + 1;
    });
  }

  /** Сменя индекса на полетата в новосъздаден ред. */
  function reindex(card, idx) {
    card.setAttribute('data-row', idx);
    card.querySelectorAll('[name]').forEach(function (el) {
      el.name = el.name.replace(/row\[\d+\]/, 'row[' + idx + ']');
    });
  }

  function nextIndex() {
    var max = -1;
    Array.prototype.forEach.call(rowsBox.children, function (c) {
      var i = rowIndex(c);
      if (!isNaN(i) && i > max) max = i;
    });
    return max + 1;
  }

  /* ---------- зареждане на компетентностите ---------- */
  function loadComps(card) {
    var subj = card.querySelector('.f-subject');
    var cls = card.querySelector('.f-class');
    var group = card.querySelector('select[name*="[group_no]"]');
    var list = card.querySelector('.clist');
    if (!subj.value || !cls.value) {
      list.innerHTML = '<p class="muted small" style="padding:.7rem">Изберете предмет и паралелка.</p>';
      updateCount(card);
      return;
    }

    list.innerHTML = '<p class="muted small" style="padding:.7rem">Зареждане…</p>';
    var url = window.MO.apiComps + '?subject=' + encodeURIComponent(subj.value) +
              '&class=' + encodeURIComponent(cls.value) +
              '&term=' + encodeURIComponent(window.MO.term) +
              '&group=' + encodeURIComponent(group ? group.value : '0');

    fetch(url, { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d.ok) { list.innerHTML = '<p class="danger small" style="padding:.7rem">' + (d.error || 'Грешка') + '</p>'; return; }
        if (!d.items.length) {
          list.innerHTML = '<p class="muted small" style="padding:.7rem">За този предмет и клас още не са въведени ' +
                           'компетентности. Обърнете се към администрацията.</p>';
          updateCount(card);
          return;
        }
        var idx = rowIndex(card);
        var html = d.items.map(function (c) {
          var state = d.saved[c.id] || '';
          var carried = d.carried.indexOf(c.id) !== -1;
          return '<label class="comp" data-state="' + state + '">' +
                   '<span class="box"></span>' +
                   '<span class="txt">' +
                     (c.code ? '<span class="code">' + esc(c.code) + '</span>' : '') +
                     esc(c.title) +
                     (carried ? ' <span class="tag">прехвърлена от I срок</span>' : '') +
                     (c.source ? '<span class="src">' + esc(c.source) + '</span>' : '') +
                   '</span>' +
                   '<input type="hidden" name="row[' + idx + '][comp][' + c.id + ']" value="' + state + '">' +
                 '</label>';
        }).join('');
        list.innerHTML = html;
        updateCount(card);
      })
      .catch(function () {
        list.innerHTML = '<p class="danger small" style="padding:.7rem">Компетентностите не се заредиха. Проверете връзката.</p>';
      });
  }

  function esc(s) {
    return String(s).replace(/[&<>"]/g, function (m) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[m];
    });
  }

  function updateCount(card) {
    var cnt = card.querySelector('.cnt');
    if (!cnt) return;
    var all = card.querySelectorAll('.comp').length;
    var ok = card.querySelectorAll('.comp[data-state="mastered"]').length;
    var no = card.querySelectorAll('.comp[data-state="not_mastered"]').length;
    cnt.textContent = all ? (ok + ' усвоени · ' + no + ' неусвоени · ' + (all - ok - no) + ' за II срок') : '';
  }

  /* ---------- количествен анализ ---------- */
  function recalc(card) {
    var vals = ['g2', 'g3', 'g4', 'g5', 'g6'].map(function (k) {
      var el = card.querySelector('input[name*="[' + k + ']"]');
      var v = el ? parseInt(el.value, 10) : 0;
      return isNaN(v) || v < 0 ? 0 : v;
    });
    var total = vals.reduce(function (a, b) { return a + b; }, 0);
    var sum = vals.reduce(function (a, b, i) { return a + b * (i + 2); }, 0);
    var t = card.querySelector('.c-tot'), a = card.querySelector('.c-avg');
    if (t) t.textContent = total || '–';
    if (a) a.textContent = total ? (sum / total).toFixed(2).replace('.', ',') : '–';
  }

  /* ---------- събития ---------- */
  form.addEventListener('change', function (ev) {
    var card = ev.target.closest('.rowcard');
    if (!card) return;
    if (ev.target.matches('.f-subject, .f-class, select[name*="[group_no]"]')) loadComps(card);
  });

  form.addEventListener('input', function (ev) {
    var card = ev.target.closest('.rowcard');
    if (card && ev.target.classList.contains('gr')) recalc(card);
  });

  // трите състояния: празно -> усвоена -> неусвоена -> празно
  form.addEventListener('click', function (ev) {
    var comp = ev.target.closest('.comp');
    if (comp) {
      ev.preventDefault();
      var cur = comp.getAttribute('data-state') || '';
      var next = STATES[(STATES.indexOf(cur) + 1) % STATES.length];
      comp.setAttribute('data-state', next);
      comp.querySelector('input').value = next;
      updateCount(comp.closest('.rowcard'));
      return;
    }

    var bulk = ev.target.closest('[data-bulk]');
    if (bulk) {
      var card = bulk.closest('.rowcard');
      var val = bulk.getAttribute('data-bulk') === 'clear' ? '' : bulk.getAttribute('data-bulk');
      card.querySelectorAll('.comp').forEach(function (c) {
        c.setAttribute('data-state', val);
        c.querySelector('input').value = val;
      });
      updateCount(card);
      return;
    }

    var del = ev.target.closest('.del');
    if (del) {
      var c2 = del.closest('.rowcard');
      if (rowsBox.children.length === 1) {
        alert('Трябва да остане поне един ред.');
        return;
      }
      if (confirm('Премахване на реда? Ако вече е записан, изтрийте го от „Моите анализи“.')) {
        c2.remove();
        renumber();
      }
    }
  });

  document.getElementById('addRow').addEventListener('click', function () {
    var idx = nextIndex();
    var card = tpl.content.firstElementChild.cloneNode(true);
    reindex(card, idx);
    card.querySelectorAll('select').forEach(function (s) { s.selectedIndex = 0; });
    card.querySelectorAll('textarea').forEach(function (t) { t.value = ''; });
    card.querySelectorAll('input[type=number]').forEach(function (i) { i.value = '0'; });
    card.querySelector('.clist').innerHTML =
      '<p class="muted small" style="padding:.7rem">Изберете предмет и паралелка.</p>';
    rowsBox.appendChild(card);
    renumber();
    card.scrollIntoView({ behavior: 'smooth', block: 'center' });
  });

  /* ---------- първоначално зареждане ---------- */
  Array.prototype.forEach.call(rowsBox.children, function (card) {
    loadComps(card);
    recalc(card);
  });
  renumber();

  /* предупреждение при напускане с незаписани промени */
  var dirty = false;
  form.addEventListener('input', function () { dirty = true; });
  form.addEventListener('click', function (ev) { if (ev.target.closest('.comp')) dirty = true; });
  form.addEventListener('submit', function () { dirty = false; });
  window.addEventListener('beforeunload', function (ev) {
    if (dirty) { ev.preventDefault(); ev.returnValue = ''; }
  });
})();
