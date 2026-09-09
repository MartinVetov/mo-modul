/* ============================================================
   Диалогов прозорец в стила на системата.
   Заменя вградените confirm() и alert() на браузъра, които
   излизат с надпис „localhost says“.

   MO.confirm({title, text, ok, cancel, danger}) -> Promise<bool>
   MO.alert({title, text, list})                 -> Promise
   ============================================================ */
(function () {
  'use strict';
  window.MO = window.MO || {};

  var open = null;

  function build(opt) {
    var back = document.createElement('div');
    back.className = 'mo-modal-back';
    back.innerHTML =
      '<div class="mo-modal" role="dialog" aria-modal="true" aria-labelledby="moModalTitle">' +
        '<div class="mo-modal-head' + (opt.danger ? ' danger' : '') + '">' +
          '<span class="ic">' + (opt.danger ? '!' : '?') + '</span>' +
          '<h2 id="moModalTitle"></h2>' +
        '</div>' +
        '<div class="mo-modal-body"><p class="txt"></p><ul class="list"></ul></div>' +
        '<div class="mo-modal-foot">' +
          '<button type="button" class="btn ghost mo-cancel"></button>' +
          '<button type="button" class="btn primary mo-ok"></button>' +
        '</div>' +
      '</div>';

    back.querySelector('h2').textContent = opt.title || 'Потвърждение';
    var txt = back.querySelector('.txt');
    txt.textContent = opt.text || '';
    if (!opt.text) txt.style.display = 'none';

    var ul = back.querySelector('.list');
    if (opt.list && opt.list.length) {
      opt.list.forEach(function (item) {
        var li = document.createElement('li');
        li.textContent = item;
        ul.appendChild(li);
      });
    } else {
      ul.style.display = 'none';
    }

    var ok = back.querySelector('.mo-ok');
    var cancel = back.querySelector('.mo-cancel');
    ok.textContent = opt.ok || 'Продължи';
    if (opt.danger) { ok.classList.remove('primary'); ok.classList.add('danger-btn'); }
    if (opt.cancel === false) {
      cancel.style.display = 'none';
    } else {
      cancel.textContent = opt.cancel || 'Откажи';
    }
    return { back: back, ok: ok, cancel: cancel };
  }

  function show(opt) {
    return new Promise(function (resolve) {
      if (open) { open.remove(); open = null; }
      var d = build(opt);
      open = d.back;
      document.body.appendChild(d.back);
      document.body.classList.add('mo-modal-open');
      setTimeout(function () { d.ok.focus(); }, 30);

      function close(val) {
        document.body.classList.remove('mo-modal-open');
        d.back.remove();
        open = null;
        document.removeEventListener('keydown', onKey);
        resolve(val);
      }
      function onKey(ev) {
        if (ev.key === 'Escape') close(false);
        if (ev.key === 'Enter' && document.activeElement !== d.cancel) close(true);
      }

      d.ok.addEventListener('click', function () { close(true); });
      d.cancel.addEventListener('click', function () { close(false); });
      d.back.addEventListener('click', function (ev) { if (ev.target === d.back) close(false); });
      document.addEventListener('keydown', onKey);
    });
  }

  MO.confirm = function (opt) { return show(typeof opt === 'string' ? { text: opt } : opt); };
  MO.alert = function (opt) {
    var o = typeof opt === 'string' ? { text: opt } : opt;
    o.cancel = false;
    o.ok = o.ok || 'Разбрах';
    o.title = o.title || 'Съобщение';
    return show(o);
  };

  /* Форми и бутони с data-confirm минават през прозореца горе. */
  document.addEventListener('submit', function (ev) {
    // Ако друга проверка вече е спряла изпращането (например заради
    // непопълнени полета), не искаме потвърждение – грешката е по-важна.
    if (ev.defaultPrevented) return;

    var form = ev.target;
    var btn = ev.submitter;
    var msg = (btn && btn.getAttribute('data-confirm')) || form.getAttribute('data-confirm');
    if (!msg || form.dataset.moConfirmed === '1') {
      delete form.dataset.moConfirmed;
      return;
    }

    ev.preventDefault();
    MO.confirm({
      title: (btn && btn.getAttribute('data-confirm-title')) || form.getAttribute('data-confirm-title') || 'Потвърждение',
      text: msg,
      ok: (btn && btn.getAttribute('data-confirm-ok')) || form.getAttribute('data-confirm-ok') || 'Потвърди',
      danger: (btn ? btn.hasAttribute('data-danger') : false) || form.hasAttribute('data-danger')
    }).then(function (yes) {
      if (!yes) return;
      form.dataset.moConfirmed = '1';

      // requestSubmit запазва кой бутон е натиснат (name=action), затова
      // сървърът получава „send“, а не стойността на първия бутон.
      if (typeof form.requestSubmit === 'function') {
        form.requestSubmit(btn || undefined);
        return;
      }
      if (btn && btn.name) {
        var hidden = document.createElement('input');
        hidden.type = 'hidden';
        hidden.name = btn.name;
        hidden.value = btn.value;
        form.appendChild(hidden);
      }
      form.submit();
    });
  });
})();

/* Модул „Анализи на МО“ – без библиотеки. */
(function () {
  'use strict';

  var form = document.getElementById('entryForm');
  if (!form) return;

  var rowsBox = form;              // формата е с един анализ
  var tpl = null;
  // цикъл при щракане: празно → усвоена → частично → неусвоена → празно
  var STATES = ['', 'mastered', 'partial', 'not_mastered'];

  function rowIndex(card) { return parseInt(card.getAttribute('data-row'), 10); }

  function renumber() { return; }
  function renumberOld() {
    Array.prototype.forEach.call(rowsBox.children, function (card, i) {
      var l = card.querySelector('.rn');
      if (l) l.textContent = i + 1;
    });
  }

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

  function esc(s) {
    return String(s).replace(/[&<>"]/g, function (m) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[m];
    });
  }

  /* ---------- зареждане на компетентностите ---------- */
  function loadComps(card) {
    var subj = card.querySelector('.f-subject');
    var cls = card.querySelector('.f-class');
    var group = card.querySelector('select[name*="[group_no]"]');
    var list = card.querySelector('.clist');
    var spec = card.querySelector('.spec-name');

    if (!subj.value || !cls.value) {
      list.innerHTML = '<p class="muted small" style="padding:.7rem">Изберете предмет и паралелка.</p>';
      if (spec) spec.textContent = '';
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
        if (!d.ok) {
          list.innerHTML = '<p class="danger small" style="padding:.7rem">' + esc(d.error || 'Грешка') + '</p>';
          return;
        }
        if (spec) {
          spec.textContent = d.program
            ? '· ' + d.program + (d.program_kind === 'profession' ? ' (професия)' : ' (специалност)')
            : '· без професия/специалност';
        }

        if (!d.items.length) {
          list.innerHTML = '<p class="muted small" style="padding:.7rem">За този предмет, клас и специалност ' +
                           'още не са въведени компетентности. Обърнете се към администрацията.</p>';
          updateCount(card);
          return;
        }

        var idx = rowIndex(card);
        var lastSection = null;

        var preset = window.MO.preset || {};
        list.innerHTML = d.items.map(function (c) {
          var state = d.saved[c.id] || preset[c.id] || '';
          var carried = d.carried.indexOf(c.id) !== -1;

          // подраздел (само за БЕЛ): заглавие през цялата ширина
          var head = '';
          var sec = c.section || '';
          if (sec !== lastSection) {
            lastSection = sec;
            if (sec) head = '<div class="comp-section">' + esc(sec) + '</div>';
          }

          return head +
                 '<div class="comp" data-state="' + state + '" role="button" tabindex="0">' +
                   '<span class="box"></span>' +
                   '<span class="txt">' +
                     (c.code ? '<span class="code">' + esc(c.code) + '</span>' : '') +
                     esc(c.title) +
                     (carried ? ' <span class="tag">прехвърлена от I срок</span>' : '') +
                     (c.program_name ? ' <span class="tag spec">' + esc(c.program_name) + '</span>' : '') +
                     (c.source ? '<span class="src">' + esc(c.source) + '</span>' : '') +
                   '</span>' +
                   '<input type="hidden" name="comp[' + c.id + ']" value="' + state + '">' +
                 '</div>';
        }).join('');
        updateCount(card);
      })
      .catch(function () {
        list.innerHTML = '<p class="danger small" style="padding:.7rem">Компетентностите не се заредиха.</p>';
      });
  }

  function updateCount(card) {
    var cnt = card.querySelector('.cnt');
    if (!cnt) return;
    var all = card.querySelectorAll('.comp').length;
    if (!all) { cnt.textContent = ''; return; }
    var ok = card.querySelectorAll('.comp[data-state="mastered"]').length;
    var pa = card.querySelectorAll('.comp[data-state="partial"]').length;
    var no = card.querySelectorAll('.comp[data-state="not_mastered"]').length;
    cnt.textContent = ok + ' усвоени · ' + pa + ' частично · ' + no + ' неусвоени · ' +
                      (all - ok - pa - no) + ' за II срок';
  }

  /* ---------- количествен анализ ---------- */
  function recalc(card) {
    var vals = ['g2', 'g3', 'g4', 'g5', 'g6'].map(function (k) {
      var el = card.querySelector('input[name="' + k + '"], input[name*="[' + k + ']"]');
      var v = el ? parseInt(el.value, 10) : 0;
      return isNaN(v) || v < 0 ? 0 : v;
    });
    var total = vals.reduce(function (a, b) { return a + b; }, 0);
    var sum = vals.reduce(function (a, b, i) { return a + b * (i + 2); }, 0);
    var stEl = card.querySelector('.students');
    var students = stEl ? parseInt(stEl.value, 10) || 0 : 0;

    var t = card.querySelector('.c-tot'), a = card.querySelector('.c-avg'), w = card.querySelector('.c-warn');
    if (t) t.textContent = total || '–';
    if (a) a.textContent = total ? (sum / total).toFixed(2).replace('.', ',') : '–';

    if (w) {
      if (students && total && total !== students) {
        var d = total - students;
        w.textContent = ' ⚠ ' + (d > 0 ? 'има ' + d + ' оценки в повече' : 'липсват ' + (-d) + ' оценки') +
                        ' спрямо ' + students + ' ученици';
        card.classList.add('bad-count');
      } else {
        w.textContent = '';
        card.classList.remove('bad-count');
      }
    }
  }

  /* ---------- събития ---------- */
  form.addEventListener('change', function (ev) {
    var card = ev.target.closest('.rowcard');
    if (!card) return;
    if (ev.target.matches('.f-subject, .f-class, select[name*="[group_no]"]')) loadComps(card);
  });

  form.addEventListener('input', function (ev) {
    var card = ev.target.closest('.rowcard');
    if (card && ev.target.matches('.gr, .students')) recalc(card);
  });

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

  });

  // достъпност: интервал и Enter въртят състоянието като щракане
  form.addEventListener('keydown', function (ev) {
    if (ev.key !== ' ' && ev.key !== 'Enter') return;
    var comp = ev.target.closest('.comp');
    if (!comp) return;
    ev.preventDefault();
    comp.click();
  });


  /* ---------- проверка преди изпращане ---------- */
  form.addEventListener('submit', function (ev) {
    if (!ev.submitter || ev.submitter.value !== 'send') return;
    var problems = [];
    Array.prototype.forEach.call(rowsBox.children, function (card, i) {
      var no = i + 1;
      var subj = card.querySelector('.f-subject').value;
      var cls = card.querySelector('.f-class').value;
      if (!subj || !cls) { problems.push('Ред ' + no + ': липсва предмет или паралелка.'); return; }

      var students = parseInt(card.querySelector('.students').value, 10) || 0;
      var total = 0;
      card.querySelectorAll('.gr').forEach(function (el) { total += parseInt(el.value, 10) || 0; });
      if (!students) problems.push('Ред ' + no + ': въведете броя ученици.');
      else if (total !== students) {
        problems.push('Ред ' + no + ': оценките са ' + total + ', а учениците ' + students + '.');
      }
      var mEl = card.querySelector('textarea[name="measures"], textarea[name*="[measures]"]');
      var m = mEl ? mEl.value.trim() : '';
      if (m.length < 10) problems.push('Ред ' + no + ': попълнете мерките за подобряване на качеството.');
    });
    if (problems.length) {
      ev.preventDefault();
      MO.alert({
        title: 'Анализът не може да се изпрати',
        text: 'Поправете следното и опитайте отново:',
        list: problems
      });
    }
  });

  /* ---------- първоначално зареждане ---------- */
  Array.prototype.forEach.call(rowsBox.children, function (card) {
    loadComps(card);
    recalc(card);
  });
  renumber();

  var dirty = false;
  form.addEventListener('input', function () { dirty = true; });
  form.addEventListener('click', function (ev) { if (ev.target.closest('.comp')) dirty = true; });
  form.addEventListener('submit', function () { dirty = false; });
  window.addEventListener('beforeunload', function (ev) {
    if (dirty) { ev.preventDefault(); ev.returnValue = ''; }
  });
})();
