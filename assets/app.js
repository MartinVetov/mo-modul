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

  /* Единен системен прозорец за грешки. Използва същия modal,
     който се използва и при потвърждение на опасни действия. */
  MO.error = function (opt) {
    var o = typeof opt === 'string' ? { text: opt } : (opt || {});
    o.cancel = false;
    o.ok = o.ok || 'Разбрах';
    o.title = o.title || 'Грешка';
    o.danger = true;
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

  /* ---------- предмети според реално въведените компетентности за паралелката ---------- */
  function classHasSubject(classId, subjectId) {
    var all = window.MO.subjectAvailability || {};
    var subjects = all[String(classId)] || [];
    return subjects.map(String).indexOf(String(subjectId)) !== -1;
  }

  /* Маршрутът към МО се изчислява само на сървъра.
     Клиентът филтрира предметите единствено по реалните компетентности. */

  function filterSubjects(card) {
    var subj = card.querySelector('.f-subject');
    var cls = card.querySelector('.f-class');
    if (!subj || !cls) return false;
    if (subj.getAttribute('data-locked') === '1') return false;

    var before = subj.value;
    var hasClass = !!cls.value;
    var availableCount = 0;
    var entrySubjectId = String(card.getAttribute('data-entry-subject') || '');
    var entryClassId = String(card.getAttribute('data-entry-class') || '');
    var preserveExistingSelection = String(window.MO.entryId || '0') !== '0' &&
      hasClass && String(cls.value || '') === entryClassId && String(before || '') === entrySubjectId;

    Array.prototype.forEach.call(subj.options, function (opt) {
      if (!opt.value) return;
      var match = hasClass && classHasSubject(cls.value, opt.value);
      /* Записаният предмет на съществуващ анализ е snapshot и остава
         избираем при Преглед/Редакция, дори текущата admin конфигурация
         вече да не го връща в subjectAvailability. */
      if (preserveExistingSelection && String(opt.value) === entrySubjectId) match = true;
      opt.hidden = !match;
      opt.disabled = !match;
      if (match) availableCount++;
    });

    var copySubjectId = String(window.MO.copySubjectId || '');
    var isCopyPreset = String(window.MO.copyMode || '0') === '1' && copySubjectId && String(before || '') === copySubjectId;

    if (subj.options.length) {
      if (!hasClass && isCopyPreset) {
        subj.options[0].textContent = '-- Изберете паралелка --';
      } else if (!hasClass) {
        subj.options[0].textContent = '-- Първо избери паралелка --';
      } else if (!availableCount) {
        subj.options[0].textContent = '-- Няма въведени предмети за този клас --';
      } else {
        subj.options[0].textContent = '-- Избери предмет --';
      }
    }

    /* При дублиране предметът трябва да остане избран, докато учителят
       избира новата паралелка. Това е особено важно за РПП, защото
       ръчните компетентности принадлежат на копирания предмет. */
    if (!hasClass && isCopyPreset) {
      Array.prototype.forEach.call(subj.options, function (opt) {
        if (!opt.value) return;
        var keep = String(opt.value) === copySubjectId;
        opt.hidden = !keep;
        opt.disabled = !keep;
      });
      subj.value = copySubjectId;
      subj.disabled = false;
      return before !== subj.value;
    }

    var selected = subj.options[subj.selectedIndex];
    if (!hasClass || !availableCount || (selected && selected.disabled)) subj.value = '';
    subj.disabled = !hasClass || !availableCount || subj.getAttribute('data-locked') === '1';
    return before !== subj.value;
  }

  function snapshotRouteApplies(card) {
    if (!card || card.getAttribute('data-has-route-snapshot') !== '1') return false;
    var cls = card.querySelector('.f-class');
    var subj = card.querySelector('.f-subject');
    var group = card.querySelector('select[name="group_no"], select[name*="[group_no]"]');
    return String(cls ? cls.value : '') === String(card.getAttribute('data-entry-class') || '') &&
      String(subj ? subj.value : '') === String(card.getAttribute('data-entry-subject') || '') &&
      String(group ? group.value : '0') === String(card.getAttribute('data-entry-group') || '0');
  }

  function syncSnapshotRouteState(card) {
    var routeCard = card ? card.querySelector('[data-route-card]') : null;
    if (!routeCard) return;
    if (snapshotRouteApplies(card)) {
      routeCard.dataset.routeReady = '1';
    } else if (card && card.getAttribute('data-has-route-snapshot') === '1') {
      routeCard.dataset.routeReady = '0';
    }
  }

  function showRoute(card, data) {
    var dep = card.querySelector('[data-route-department]');
    var recipient = card.querySelector('[data-route-recipient]');
    var routeCard = card.matches && card.matches('[data-route-card]') ? card : card.querySelector('[data-route-card]');
    if (!dep || !recipient) return;

    function setReady(v) {
      card.dataset.routeReady = v;
      if (routeCard) routeCard.dataset.routeReady = v;
    }

    if (!data) {
      dep.textContent = 'Изберете паралелка и предмет';
      recipient.textContent = 'Получателят ще бъде определен автоматично.';
      recipient.classList.remove('danger');
      setReady('0');
      return;
    }

    dep.textContent = data.department || 'Няма зададено методическо обединение';
    if (data.can_send) {
      var modeLabel = data.route_mode === 'professional'
        ? ' · по професия/специалност'
        : (data.route_mode === 'general' ? ' · общообразователен предмет' : '');
      recipient.textContent = 'Получател: ' + (data.methodist || data.methodist_email || 'председател на МО') + modeLabel;
      recipient.classList.remove('danger');
      setReady('1');
    } else {
      recipient.textContent = data.route_error || 'Няма назначен председател.';
      recipient.classList.add('danger');
      setReady('0');
    }
  }


  function manualStateSymbol(state) {
    if (state === 'mastered') return '✓';
    if (state === 'partial') return '–';
    if (state === 'not_mastered') return '✕';
    return '';
  }

  function setManualState(row, state) {
    row.setAttribute('data-state', state || '');
    var hidden = row.querySelector('[data-manual-state-input]');
    var btn = row.querySelector('[data-manual-state]');
    if (hidden) hidden.value = state || '';
    if (btn) {
      btn.textContent = manualStateSymbol(state || '');
      btn.setAttribute('aria-label', state === 'mastered' ? 'Усвоена' : (state === 'partial' ? 'Частично усвоена' : (state === 'not_mastered' ? 'Неусвоена' : 'Неотбелязана')));
    }
  }

  function nextManualIndex(card) {
    var max = -1;
    card.querySelectorAll('.manual-comp-row').forEach(function (row) {
      var n = parseInt(row.getAttribute('data-manual-index'), 10);
      if (!isNaN(n) && n > max) max = n;
    });
    return max + 1;
  }

  function manualRowHtml(item, idx, locked) {
    item = item || {};
    var state = item.state || '';
    var title = item.title || '';
    var carried = item.carried ? '<span class="tag">прехвърлена от I срок</span>' : '';
    var dis = locked ? ' disabled' : '';
    return '<div class="manual-comp-row" data-manual-index="' + idx + '" data-state="' + esc(state) + '">' +
      '<button type="button" class="manual-state-box" data-manual-state' + dis + ' aria-label="Състояние">' + manualStateSymbol(state) + '</button>' +
      '<div class="manual-comp-text"><input type="text" name="manual_comp[' + idx + '][title]" value="' + esc(title) + '" placeholder="Въведете компетентност…" maxlength="600"' + dis + '>' + carried + '</div>' +
      '<input type="hidden" name="manual_comp[' + idx + '][state]" value="' + esc(state) + '" data-manual-state-input>' +
      (locked ? '' : '<button type="button" class="manual-comp-remove" data-manual-remove aria-label="Премахни компетентността">×</button>') +
      '</div>';
  }

  function collectManualRows(card) {
    var rows = [];
    card.querySelectorAll('.manual-comp-row').forEach(function (row) {
      var title = row.querySelector('input[type="text"]');
      rows.push({ title: title ? title.value : '', state: row.getAttribute('data-state') || '' });
    });
    return rows;
  }

  function renderManualComps(card, data, localItems) {
    var list = card.querySelector('.clist');
    var addBtn = card.querySelector('[data-add-manual]');
    var title = card.querySelector('[data-comp-title]');
    card.querySelector('.comps').classList.add('is-rpp');
    if (title) title.firstChild.nodeValue = 'Компетентности РПП ';
    if (addBtn) {
      addBtn.hidden = !!data.locked;
      addBtn.disabled = !!data.locked;
    }

    var items = Array.isArray(data.manual_items) ? data.manual_items.slice() : [];
    if (!items.length && Array.isArray(localItems) && localItems.length) items = localItems;
    var presetSubject = String(window.MO.manualPresetSubject || '');
    var currentSubject = String(card.querySelector('.f-subject').value || '');
    if (!items.length && presetSubject && presetSubject === currentSubject && Array.isArray(window.MO.manualPreset)) {
      items = window.MO.manualPreset.map(function (x) { return { title: x.title || '', state: x.state || '' }; });
    }
    if (!items.length && !data.locked) items.push({ title: '', state: '' });

    if (!items.length) {
      list.innerHTML = '<p class="muted small manual-empty">Няма въведени компетентности за този РПП анализ.</p>';
    } else {
      list.innerHTML = items.map(function (item, i) { return manualRowHtml(item, i, !!data.locked); }).join('');
    }
    updateCount(card);
  }

  function isCopyStandardPreset(card) {
    if (!card) return false;
    var subj = card.querySelector('.f-subject');
    return String(window.MO.copyMode || '0') === '1' &&
      String(window.MO.copyIsRpp || '0') !== '1' &&
      String(window.MO.copySubjectId || '') !== '' &&
      String(window.MO.copySubjectId || '') === String(subj ? subj.value : '');
  }

  /* Ако за новата паралелка компетентностите са отделни записи в БД,
     опитваме да пренесем отметката и по код/заглавие, а не само по id. */
  function copyStandardPresetState(comp) {
    var preset = window.MO.preset || {};
    if (Object.prototype.hasOwnProperty.call(preset, comp.id)) return preset[comp.id] || '';

    var items = Array.isArray(window.MO.copyStandardItems) ? window.MO.copyStandardItems : [];
    var code = String(comp.code || '').trim();
    var title = String(comp.title || '').trim();
    for (var i = 0; i < items.length; i++) {
      var item = items[i] || {};
      if (code && String(item.code || '').trim() === code && String(item.title || '').trim() === title) {
        return item.state || '';
      }
    }
    for (var j = 0; j < items.length; j++) {
      var byTitle = items[j] || {};
      if (title && String(byTitle.title || '').trim() === title) return byTitle.state || '';
    }
    return '';
  }

  /* ---------- зареждане на компетентностите ---------- */
  function loadComps(card) {
    var subj = card.querySelector('.f-subject');
    var cls = card.querySelector('.f-class');
    var group = card.querySelector('select[name="group_no"], select[name*="[group_no]"]');
    var list = card.querySelector('.clist');
    var spec = card.querySelector('.spec-name');
    var compsBox = card.querySelector('.comps');
    var compTitle = card.querySelector('[data-comp-title]');
    var addManual = card.querySelector('[data-add-manual]');
    var serverEntryRpp = card.dataset.entryRpp === '1' &&
      String(window.MO.entryIsRpp || '0') === '1' &&
      String(window.MO.manualPresetSubject || '') === String(subj.value || '');
    var serverEntryStandard = card.dataset.entryStandard === '1' &&
      String(window.MO.entryId || '0') !== '0' &&
      String(card.getAttribute('data-entry-subject') || '') === String(subj.value || '') &&
      String(card.getAttribute('data-entry-class') || '') === String(cls.value || '');
    if (compsBox && !serverEntryRpp) compsBox.classList.remove('is-rpp');
    if (compTitle && !serverEntryRpp) compTitle.firstChild.nodeValue = 'Компетентности ';
    if (addManual && !serverEntryRpp) addManual.hidden = true;

    syncSnapshotRouteState(card);

    if (!subj.value || !cls.value) {
      /* В copy mode на РПП пазим копираните ръчни компетентности на екрана,
         докато се избере новата допустима паралелка. */
      var copyRppWaitingForClass = String(window.MO.copyMode || '0') === '1' &&
        String(window.MO.copyIsRpp || '0') === '1' &&
        String(window.MO.copySubjectId || '') === String(subj.value || '');
      var copyStandardWaitingForClass = isCopyStandardPreset(card);
      if (copyRppWaitingForClass && Array.isArray(window.MO.manualPreset)) {
        renderManualComps(card, {
          manual_items: window.MO.manualPreset,
          locked: false
        }, collectManualRows(card));
      } else if (copyStandardWaitingForClass) {
        /* Стандартното копие вече е рендерирано server-side. Не го
           заменяме с празен текст само защото новата паралелка още не е избрана. */
        updateCount(card);
      } else {
        list.innerHTML = '<p class="muted small" style="padding:.7rem">Изберете предмет и паралелка.</p>';
      }
      if (spec) spec.textContent = '';
      if (!snapshotRouteApplies(card)) showRoute(card, null);
      updateCount(card);
      return;
    }

    var preserveCopyRppManual = String(window.MO.copyMode || '0') === '1' &&
      String(window.MO.copyIsRpp || '0') === '1' &&
      String(window.MO.copySubjectId || '') === String(subj.value || '');
    var preserveCopyStandard = isCopyStandardPreset(card);
    var keepLocalManual = preserveCopyRppManual ||
      (card.dataset.loadedSubject === String(subj.value) && card.dataset.loadedClass === String(cls.value))
      ? collectManualRows(card) : [];
    /* При съществуващ анализ (РПП или стандартен) вече имаме server-side
       snapshot. Не го заличаваме с „Зареждане…“, докато API се изпълнява. */
    if (!serverEntryRpp && !serverEntryStandard && !preserveCopyStandard) list.innerHTML = '<p class="muted small" style="padding:.7rem">Зареждане…</p>';
    if (!window.MO.entryId) showRoute(card, null);
    var url = window.MO.apiComps + '?subject=' + encodeURIComponent(subj.value) +
              '&class=' + encodeURIComponent(cls.value) +
              '&term=' + encodeURIComponent(window.MO.term) +
              '&group=' + encodeURIComponent(group ? group.value : '0') +
              '&entry_id=' + encodeURIComponent(window.MO.entryId || '0') +
              '&copy_mode=' + encodeURIComponent(window.MO.copyMode || '0');

    fetch(url, { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d.ok) {
          if (serverEntryRpp) {
            renderManualComps(card, {
              manual_items: Array.isArray(window.MO.manualPreset) ? window.MO.manualPreset : [],
              locked: !!card.querySelector('.f-subject[disabled]')
            }, keepLocalManual);
            if (snapshotRouteApplies(card)) syncSnapshotRouteState(card);
            return;
          }
          if (serverEntryStandard || preserveCopyStandard) {
            /* Запазваме server-side стандартните компетентности и отметки.
               При дублиране те са snapshot на оригинала, докато се избира нов клас. */
            if (snapshotRouteApplies(card)) syncSnapshotRouteState(card);
            updateCount(card);
            return;
          }
          list.innerHTML = '<p class="danger small" style="padding:.7rem">' + esc(d.error || 'Грешка') + '</p>';
          if (!snapshotRouteApplies(card)) {
            showRoute(card, { department: '', can_send: false, route_error: d.error || 'Маршрутът не е настроен.' });
          } else {
            syncSnapshotRouteState(card);
          }
          return;
        }
        showRoute(card, d);
        if (spec) {
          spec.textContent = d.program
            ? '· ' + d.program + (d.program_kind === 'profession' ? ' (професия)' : ' (специалност)')
            : '· без професия/специалност';
        }

        card.dataset.loadedSubject = String(subj.value);
        card.dataset.loadedClass = String(cls.value);
        if (d.is_rpp || serverEntryRpp) {
          if (!Array.isArray(d.manual_items) || !d.manual_items.length) {
            d.manual_items = Array.isArray(window.MO.manualPreset) ? window.MO.manualPreset : [];
          }
          renderManualComps(card, d, keepLocalManual);
          return;
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
          var state = d.saved[c.id] || preset[c.id] || (preserveCopyStandard ? copyStandardPresetState(c) : '') || '';
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
        if (serverEntryRpp) {
          renderManualComps(card, {
            manual_items: Array.isArray(window.MO.manualPreset) ? window.MO.manualPreset : [],
            locked: !!card.querySelector('.f-subject[disabled]')
          }, keepLocalManual);
          return;
        }
        if (serverEntryStandard || preserveCopyStandard) {
          updateCount(card);
          if (snapshotRouteApplies(card)) syncSnapshotRouteState(card);
          return;
        }
        list.innerHTML = '<p class="danger small" style="padding:.7rem">Компетентностите не се заредиха.</p>';
        if (!window.MO.entryId) showRoute(card, { department: '', can_send: false, route_error: 'Маршрутът не може да бъде проверен.' });
      });
  }

  function updateCount(card) {
    var cnt = card.querySelector('.cnt');
    if (!cnt) return;
    var all = card.querySelectorAll('.comp, .manual-comp-row').length;
    if (!all) { cnt.textContent = ''; return; }
    var ok = card.querySelectorAll('.comp[data-state="mastered"], .manual-comp-row[data-state="mastered"]').length;
    var pa = card.querySelectorAll('.comp[data-state="partial"], .manual-comp-row[data-state="partial"]').length;
    var no = card.querySelectorAll('.comp[data-state="not_mastered"], .manual-comp-row[data-state="not_mastered"]').length;
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

    if (ev.target.matches('.f-class')) {
      filterSubjects(card);
      syncSnapshotRouteState(card);
      loadComps(card);
      return;
    }
    if (ev.target.matches('.f-subject, select[name="group_no"], select[name*="[group_no]"]')) {
      syncSnapshotRouteState(card);
      loadComps(card);
    }
  });

  form.addEventListener('input', function (ev) {
    var card = ev.target.closest('.rowcard');
    if (card && ev.target.matches('.gr, .students')) recalc(card);
  });

  form.addEventListener('click', function (ev) {
    var manualState = ev.target.closest('[data-manual-state]');
    if (manualState) {
      ev.preventDefault();
      var mrow = manualState.closest('.manual-comp-row');
      var mcur = mrow ? (mrow.getAttribute('data-state') || '') : '';
      var mnext = STATES[(STATES.indexOf(mcur) + 1) % STATES.length];
      if (mrow) setManualState(mrow, mnext);
      updateCount(manualState.closest('.rowcard'));
      return;
    }

    var manualRemove = ev.target.closest('[data-manual-remove]');
    if (manualRemove) {
      ev.preventDefault();
      var removeRow = manualRemove.closest('.manual-comp-row');
      var removeCard = manualRemove.closest('.rowcard');
      if (removeRow) removeRow.remove();
      updateCount(removeCard);
      return;
    }

    var addManual = ev.target.closest('[data-add-manual]');
    if (addManual) {
      ev.preventDefault();
      var addCard = addManual.closest('.rowcard');
      var addList = addCard.querySelector('.clist');
      var empty = addList.querySelector('.manual-empty');
      if (empty) empty.remove();
      var idx = nextManualIndex(addCard);
      addList.insertAdjacentHTML('beforeend', manualRowHtml({title:'',state:''}, idx, false));
      var last = addList.querySelector('.manual-comp-row:last-child input[type="text"]');
      if (last) last.focus();
      updateCount(addCard);
      return;
    }

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
      card.querySelectorAll('.manual-comp-row').forEach(function (c) { setManualState(c, val); });
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


  /* ---------- изпращане без презареждане ---------- */
  function entryStatus(type, text) {
    var box = document.getElementById('entryAjaxStatus');
    if (!box) return;
    box.className = 'flash ' + (type || 'ok');
    box.textContent = text || '';
    box.style.display = text ? '' : 'none';
  }

  function responseFlash(html) {
    var doc = new DOMParser().parseFromString(html, 'text/html');
    var f = doc.querySelector('.flash.err, .flash.warn, .flash.ok, .flash');
    if (!f) return null;
    var type = f.classList.contains('err') ? 'err' : (f.classList.contains('warn') ? 'warn' : 'ok');
    return { type: type, text: (f.textContent || '').trim() };
  }

  function setEntryBusy(busy) {
    form.querySelectorAll('button[type="submit"]').forEach(function (b) {
      b.disabled = !!busy;
      b.classList.toggle('is-busy', !!busy);
    });
  }

  function lockEntryAfterSend() {
    form.querySelectorAll('input, select, textarea, button').forEach(function (el) {
      if (el.type === 'hidden') return;
      el.disabled = true;
    });
    var actions = form.querySelector('.actions');
    if (actions) actions.innerHTML = '<a class="btn primary" href="' +
      (window.MO.myEntries || 'my_entries.php') + '">Към моите анализи</a>';
  }

  form.addEventListener('submit', function (ev) {
    var btn = ev.submitter;
    var action = btn ? btn.value : 'draft';
    var isSend = action === 'send';

    if (isSend) {
      var problems = [];
      Array.prototype.forEach.call(rowsBox.children, function (card, i) {
        if (!card.classList || !card.classList.contains('rowcard')) return;
        var no = i + 1;
        var subjEl = card.querySelector('.f-subject');
        var clsEl = card.querySelector('.f-class');
        var subj = subjEl ? subjEl.value : '';
        var cls = clsEl ? clsEl.value : '';
        if (!subj || !cls) { problems.push('Липсва предмет или паралелка.'); return; }
        var routeCard = card.querySelector('[data-route-card]');
        var routeReady = routeCard ? routeCard.dataset.routeReady === '1' : card.dataset.routeReady === '1';
        if (!routeReady && snapshotRouteApplies(card)) routeReady = true;
        if (!routeReady) problems.push('За избрания предмет няма готов маршрут към председател на МО.');
        if (card.querySelector('.comps.is-rpp')) {
          var hasManual = Array.prototype.some.call(card.querySelectorAll('.manual-comp-row input[type="text"]'), function (el) { return el.value.trim().length > 0; });
          if (!hasManual) problems.push('Добавете поне една компетентност за РПП предмета.');
        }

        var stEl = card.querySelector('.students');
        var students = stEl ? (parseInt(stEl.value, 10) || 0) : 0;
        var total = 0;
        card.querySelectorAll('.gr').forEach(function (el) { total += parseInt(el.value, 10) || 0; });
        if (!students) problems.push('Въведете броя ученици.');
        else if (total !== students) problems.push('Оценките са ' + total + ', а учениците ' + students + '.');

        var mEl = card.querySelector('textarea[name="measures"], textarea[name*="[measures]"]');
        var m = mEl ? mEl.value.trim() : '';
        if (m.length < 10) problems.push('Попълнете мерките за подобряване на качеството.');
      });

      if (problems.length) {
        ev.preventDefault();
        MO.error({ title: 'Анализът не може да се изпрати', list: problems });
        return;
      }

      // Първото submit-събитие оставяме на общия data-confirm обработчик.
      // След потвърждение той извиква requestSubmit() втори път с moConfirmed=1.
      var msg = btn && btn.getAttribute('data-confirm');
      if (msg && form.dataset.moConfirmed !== '1') return;
    }

    ev.preventDefault();
    entryStatus('', '');
    setEntryBusy(true);

    var data = new FormData(form);
    if (btn && btn.name) data.set(btn.name, btn.value);

    // POST към точния URL, на който е заредена страницата. Това е важно
    // когато модулът е вграден във ВИС и SCRIPT_NAME не сочи директно към
    // /mo-modul/pages/entry.php. Не използваме base_url/form.action тук.
    var entryPostUrl = window.location.pathname + window.location.search;

    fetch(entryPostUrl, {
      method: 'POST',
      body: data,
      credentials: 'same-origin',
      headers: {
        'X-Requested-With': 'XMLHttpRequest',
        'Accept': 'application/json'
      }
    }).then(function (r) {
      return r.text().then(function (text) {
        var payload = null;
        try { payload = JSON.parse(text); } catch (_) {}
        return { response: r, payload: payload, text: text };
      });
    }).then(function (res) {
      var d = res.payload;
      if (!d || typeof d.ok === 'undefined') {
        var status = res.response && res.response.status ? ('HTTP ' + res.response.status + '. ') : '';
        MO.error(status + 'Сървърът не върна валиден отговор за записа. Презаредете страницата и опитайте отново.');
        return;
      }

      if (!d.ok) {
        MO.error(d.message || 'Записът не беше извършен. Проверете данните.');
        return;
      }

      if (d.action === 'send') {
        dirty = false;
        entryStatus('ok', d.message || 'Анализът е изпратен успешно.');
        lockEntryAfterSend();
        return;
      }

      if (d.action === 'draft') {
        var id = d.entry_id ? String(d.entry_id) : '';
        if (id) {
          var hidden = form.querySelector('input[name="entry_id"]');
          if (hidden) hidden.value = id;
          window.MO.entryId = id;
        }
        if (d.redirect) history.replaceState({}, '', d.redirect);
        dirty = false;
        entryStatus('ok', d.message || 'Черновата е записана.');
        return;
      }

      MO.error(d.message || 'Сървърът върна непознат тип отговор.');
    }).catch(function () {
      MO.error('Възникна мрежова грешка. Данните във формата са запазени на екрана; опитайте отново.');
    }).finally(function () {
      setEntryBusy(false);
    });
  });

  /* ---------- първоначално зареждане ---------- */
  Array.prototype.forEach.call(rowsBox.children, function (card) {
    filterSubjects(card);
    syncSnapshotRouteState(card);
    loadComps(card);
    recalc(card);
  });
  renumber();

  var dirty = false;
  form.addEventListener('input', function () { dirty = true; });
  form.addEventListener('click', function (ev) { if (ev.target.closest('.comp, [data-manual-state], [data-manual-remove], [data-add-manual]')) dirty = true; });
  window.addEventListener('beforeunload', function (ev) {
    if (dirty) { ev.preventDefault(); ev.returnValue = ''; }
  });
})();


/* ============================================================
   Администрация на МО без пълно презареждане.
   POST връща JSON след успешен commit. Едва тогава правим GET на
   текущата страница и подменяме административните панели.
   ============================================================ */
(function () {
  'use strict';

  var depPanel = document.getElementById('departmentsPanel');
  var routeStatus = document.getElementById('routingStatus');
  if (!depPanel) return;

  // URL-ът се подава от PHP, вместо да се извежда от window.location.
  // Това пази AJAX записването при различни XAMPP поддиректории/URL-и.
  var adminEndpoint = routeStatus && routeStatus.getAttribute('data-admin-endpoint')
    ? routeStatus.getAttribute('data-admin-endpoint')
    : null;

  function toast(type, text) {
    var old = document.getElementById('adminAjaxToast');
    if (old) old.remove();
    if (!text) return;
    var box = document.createElement('div');
    box.id = 'adminAjaxToast';
    box.className = 'flash ' + (type || 'ok');
    box.setAttribute('aria-live', 'polite');
    box.textContent = text;
    document.body.appendChild(box);
    setTimeout(function () { if (box.parentNode) box.remove(); }, 3800);
  }

  function replacePanels(html, section, oldY) {
    var doc = new DOMParser().parseFromString(html, 'text/html');
    var ids = ['routingStatus', 'departmentsPanel', 'rppPanel'];
    var replaced = 0;
    ids.forEach(function (id) {
      var incoming = doc.getElementById(id);
      var current = document.getElementById(id);
      if (incoming && current) {
        current.replaceWith(incoming);
        replaced++;
      }
    });
    if (!replaced) throw new Error('Сървърът не върна очакваните административни панели.');
    requestAnimationFrame(function () { window.scrollTo(window.scrollX, oldY); });
  }

  function refreshPanels(section, oldY) {
    var url = adminEndpoint || window.location.href;
    return fetch(url, {
      method: 'GET',
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      cache: 'no-store'
    }).then(function (r) {
      if (!r.ok) throw new Error('Страницата не можа да бъде обновена след записа. HTTP ' + r.status);
      return r.text();
    }).then(function (html) {
      replacePanels(html, section, oldY);
    });
  }

  function setBusy(form, busy) {
    if (busy) form.dataset.ajaxBusy = '1';
    else delete form.dataset.ajaxBusy;
    form.querySelectorAll('button[type="submit"], input[type="submit"]').forEach(function (btn) {
      btn.disabled = !!busy;
    });
    var state = form.querySelector('.ajax-save-state');
    if (state) state.textContent = busy ? 'Записване…' : '';
  }

  function ajaxSubmit(form, submitter) {
    if (form.dataset.ajaxBusy === '1') return;
    setBusy(form, true);
    var oldY = window.scrollY;
    var section = form.getAttribute('data-refresh') || 'all';
    var data = new FormData(form);
    if (submitter && submitter.name) data.set(submitter.name, submitter.value);

    var postUrl = form.getAttribute('action') || adminEndpoint || window.location.href;
    fetch(postUrl, {
      method: 'POST',
      body: data,
      credentials: 'same-origin',
      headers: {
        'X-Requested-With': 'XMLHttpRequest',
        'Accept': 'application/json'
      },
      cache: 'no-store'
    }).then(function (r) {
      return r.text().then(function (text) {
        var payload;
        try { payload = JSON.parse(text); }
        catch (e) {
          var detail = (text || '').replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();
          throw new Error(detail || ('Невалиден отговор от сървъра. HTTP ' + r.status));
        }
        if (!r.ok || !payload.ok) {
          var err = new Error(payload.message || ('Записът е отказан. HTTP ' + r.status));
          err.payload = payload;
          throw err;
        }
        return payload;
      });
    }).then(function (payload) {
      return refreshPanels(payload.refresh || section, oldY).then(function () {
        if (payload.type === 'warn') {
          toast('warn', payload.message || 'Промяната е записана с предупреждение.');
        } else {
          toast('ok', payload.message || 'Промяната е записана.');
        }
      });
    }).catch(function (err) {
      MO.error({
        title: 'Промяната не е записана',
        text: err && err.message ? err.message : 'Възникна грешка при записването.'
      });
    }).finally(function () {
      // Ако панелът е бил заменен, старата форма вече не е в DOM; това е безвредно.
      setBusy(form, false);
    });
  }

  document.addEventListener('submit', function (ev) {
    var form = ev.target.closest('form[data-ajax-admin]');
    if (!form) return;

    var btn = ev.submitter;
    var msg = (btn && btn.getAttribute('data-confirm')) || form.getAttribute('data-confirm');
    if (msg && form.dataset.moConfirmed !== '1') {
      // Оставяме общия confirm handler да покаже диалога.
      return;
    }

    ev.preventDefault();
    ajaxSubmit(form, btn);
  });

  document.addEventListener('change', function (ev) {
    var select = ev.target.closest('select[data-autosave]');
    if (!select) return;
    var form = select.closest('form[data-ajax-admin]');
    if (!form || !select.value) return;
    if (typeof form.requestSubmit === 'function') form.requestSubmit();
    else ajaxSubmit(form, null);
  });
})();

/* ============================================================
   Компактни multi-select pill контроли в администрацията.
   Показваме само избраните стойности; останалите са в "Добави…".
   ============================================================ */
(function () {
  'use strict';

  function updateEmpty(picker) {
    var selected = picker.querySelector('[data-pill-selected]');
    var empty = picker.querySelector('[data-pill-empty]');
    if (!selected || !empty) return;
    var hasPills = !!selected.querySelector('.selection-pill');
    empty.classList.toggle('is-hidden', hasPills);
  }

  function hasValue(picker, value) {
    return !!picker.querySelector('[data-pill-input="' + CSS.escape(String(value)) + '"]');
  }

  document.addEventListener('change', function (ev) {
    var select = ev.target.closest('select[data-pill-add]');
    if (!select || !select.value) return;

    var picker = select.closest('[data-pill-picker]');
    if (!picker) return;

    var value = String(select.value);
    var option = select.options[select.selectedIndex];
    var label = option ? option.textContent.trim() : value;
    if (hasValue(picker, value)) {
      select.value = '';
      return;
    }

    var inputName = picker.getAttribute('data-input-name') || '';
    if (!inputName) return;

    var hiddenBox = picker.querySelector('[data-pill-hidden]');
    var selectedBox = picker.querySelector('[data-pill-selected]');
    if (!hiddenBox || !selectedBox) return;

    var input = document.createElement('input');
    input.type = 'hidden';
    input.name = inputName;
    input.value = value;
    input.setAttribute('data-pill-input', value);
    hiddenBox.appendChild(input);

    var pill = document.createElement('span');
    pill.className = 'selection-pill';
    pill.setAttribute('data-pill-value', value);

    var text = document.createElement('span');
    text.textContent = label;
    pill.appendChild(text);

    var remove = document.createElement('button');
    remove.type = 'button';
    remove.className = 'pill-remove';
    remove.setAttribute('data-pill-remove', '');
    remove.setAttribute('aria-label', 'Премахни ' + label);
    remove.textContent = '×';
    pill.appendChild(remove);

    selectedBox.appendChild(pill);
    if (option) option.remove();
    select.value = '';
    updateEmpty(picker);
  });

  document.addEventListener('click', function (ev) {
    var remove = ev.target.closest('[data-pill-remove]');
    if (!remove) return;

    var pill = remove.closest('.selection-pill');
    var picker = remove.closest('[data-pill-picker]');
    if (!pill || !picker) return;

    var value = pill.getAttribute('data-pill-value') || '';
    var labelNode = pill.querySelector('span');
    var label = labelNode ? labelNode.textContent.trim() : value;

    var hidden = picker.querySelector('[data-pill-input="' + CSS.escape(String(value)) + '"]');
    if (hidden) hidden.remove();

    var select = picker.querySelector('select[data-pill-add]');
    if (select && value) {
      var option = document.createElement('option');
      option.value = value;
      option.textContent = label;
      select.appendChild(option);
    }

    pill.remove();
    updateEmpty(picker);
  });
})();
