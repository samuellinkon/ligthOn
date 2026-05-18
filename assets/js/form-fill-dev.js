/**
 * Ferramenta de desenvolvimento: preenche formulários com dados fictícios.
 * Só roda com <body data-form-fill-dev="1"> (ver includes/config.php + config.local.php).
 */
(function () {
  'use strict';

  if (!document.body || document.body.getAttribute('data-form-fill-dev') !== '1') return;

  var SKIP_TYPES = { hidden: 1, submit: 1, button: 1, reset: 1, file: 1, image: 1 };

  function normKey(el) {
    var n = (el.name || '').toLowerCase();
    var i = (el.id || '').toLowerCase();
    return n + ' ' + i;
  }

  function shouldSkipForm(form) {
    if (form.hasAttribute('data-no-test-fill')) return true;
    if (form.getAttribute('method') && String(form.getAttribute('method')).toLowerCase() === 'get') return true;
    var st = (form.getAttribute('style') || '').replace(/\s+/g, '').toLowerCase();
    if (st.indexOf('display:inline') !== -1) return true;
    var ons = (form.getAttribute('onsubmit') || '').toLowerCase();
    if (ons.indexOf('confirm') !== -1 && (ons.indexOf('excluir') !== -1 || ons.indexOf('remover') !== -1)) return true;
    return false;
  }

  function isFillableInput(el) {
    if (el.disabled || el.readOnly || el.hidden) return false;
    var t = (el.type || 'text').toLowerCase();
    if (SKIP_TYPES[t]) return false;
    return (
      t === 'text' ||
      t === 'email' ||
      t === 'tel' ||
      t === 'url' ||
      t === 'search' ||
      t === 'password' ||
      t === 'number' ||
      t === 'date' ||
      t === 'time' ||
      t === 'datetime-local' ||
      t === 'month' ||
      t === 'week' ||
      t === 'color' ||
      t === 'range'
    );
  }

  function formHasFillableFields(form) {
    var els = form.elements;
    for (var i = 0; i < els.length; i++) {
      var el = els[i];
      if (!el || el.nodeType !== 1) continue;
      var tag = el.tagName;
      if (tag === 'TEXTAREA' && !el.disabled && !el.readOnly && !el.hidden) return true;
      if (tag === 'SELECT' && !el.disabled && !el.hidden) return true;
      if (tag === 'INPUT' && isFillableInput(el)) return true;
    }
    return false;
  }

  function pickTextValue(key, type) {
    if (type === 'email' || key.indexOf('email') !== -1 || key.indexOf('mail') !== -1) {
      return 'teste.validacao+' + Date.now() + '@exemplo.com.br';
    }
    if (type === 'password' || key.indexOf('senha') !== -1 || key.indexOf('password') !== -1) {
      return 'Validacao@123';
    }
    if (key.indexOf('cpf') !== -1 && key.indexOf('cnpj') === -1) return '529.982.247-35';
    if (key.indexOf('cnpj') !== -1) return '12.345.678/0001-95';
    if (key.indexOf('cep') !== -1) return '01310-100';
    if (key.indexOf('telefone') !== -1 || key.indexOf('tel') !== -1 || key.indexOf('phone') !== -1 || type === 'tel') {
      return '(11) 98877-6655';
    }
    if (key.indexOf('url') !== -1 || key.indexOf('site') !== -1 || key.indexOf('link') !== -1 || type === 'url') {
      return 'https://www.exemplo.com.br/teste';
    }
    if (key.indexOf('titulo') !== -1 || key.indexOf('assunto') !== -1) return 'Registro de teste automatizado';
    if (key.indexOf('latitude') !== -1) return '-23.5614140';
    if (key.indexOf('longitude') !== -1) return '-46.6558810';
    if (key.indexOf('endereco_completo') !== -1 || (key.indexOf('endereco') !== -1 && key.indexOf('completo') !== -1)) {
      return 'Av. Paulista, 1578 - Bela Vista, São Paulo - SP, CEP 01310-200';
    }
    if (key.indexOf('nome') !== -1 && key.indexOf('empresa') === -1) return 'João Validação Silva';
    if (key.indexOf('empresa') !== -1 || key.indexOf('company') !== -1) return 'Empresa Teste Ltda';
    if (key.indexOf('doc') !== -1 || key.indexOf('cnpj') !== -1 || key.indexOf('cpf') !== -1) return '529.982.247-35';
    if (key.indexOf('pix') !== -1) return 'teste@exemplo.com.br';
    if (key.indexOf('banco') !== -1) return '341';
    if (key.indexOf('agencia') !== -1) return '1234';
    if (key.indexOf('conta') !== -1 && key.indexOf('contrato') === -1) return '12345-6';
    if (key.indexOf('valor') !== -1 || key.indexOf('preco') !== -1 || key.indexOf('preço') !== -1) return '150,75';
    if (key.indexOf('horas') !== -1) return '2,5';
    if (key.indexOf('dia') !== -1) return '15';
    if (key.indexOf('quantidade') !== -1 || key.indexOf('qtd') !== -1) return '2';
    if (key.indexOf('descricao') !== -1 || key.indexOf('descrição') !== -1 || key.indexOf('mensagem') !== -1 ||
        key.indexOf('obs') !== -1 || key.indexOf('nota') !== -1 || key.indexOf('texto') !== -1) {
      return 'Texto de teste para validação do formulário.\nSegunda linha com detalhes fictícios.';
    }
    return 'Valor de teste';
  }

  function fillInput(el) {
    var key = normKey(el);
    var type = (el.type || 'text').toLowerCase();

    if (type === 'number') {
      var mn = el.min !== '' ? parseFloat(el.min) : NaN;
      var mx = el.max !== '' ? parseFloat(el.max) : NaN;
      var step = el.step && el.step !== 'any' ? parseFloat(el.step) : 1;
      var v = 10;
      if (key.indexOf('hora') !== -1) v = 2.5;
      if (key.indexOf('valor') !== -1 || key.indexOf('dia') !== -1) v = key.indexOf('dia') !== -1 ? 15 : 150.75;
      if (!isNaN(mn) && v < mn) v = mn;
      if (!isNaN(mx) && v > mx) v = mx;
      el.value = String(v);
      return;
    }
    if (type === 'date') {
      el.value = new Date().toISOString().slice(0, 10);
      return;
    }
    if (type === 'time') {
      el.value = '14:30';
      return;
    }
    if (type === 'datetime-local') {
      var d = new Date();
      d.setMinutes(0, 0, 0);
      el.value = d.toISOString().slice(0, 16);
      return;
    }
    if (type === 'month') {
      el.value = new Date().toISOString().slice(0, 7);
      return;
    }
    if (type === 'week') {
      el.value = new Date().toISOString().slice(0, 4) + '-W15';
      return;
    }
    if (type === 'color') {
      el.value = '#534ab7';
      return;
    }
    if (type === 'range') {
      var min = el.min !== '' ? +el.min : 0;
      var max = el.max !== '' ? +el.max : 100;
      el.value = String(Math.round((min + max) / 2));
      return;
    }

    el.value = pickTextValue(key, type);
  }

  function fillTextarea(el) {
    el.value = pickTextValue(normKey(el), 'textarea');
  }

  function fillSelect(sel) {
    var opts = Array.prototype.slice.call(sel.options, 0);
    var usable = opts.filter(function (o) { return !o.disabled && o.value !== ''; });
    if (usable.length) {
      sel.value = usable[0].value;
      return;
    }
    var nonPh = opts.filter(function (o) { return !o.disabled; });
    if (nonPh.length > 1) sel.selectedIndex = opts.indexOf(nonPh[1]);
    else if (nonPh.length === 1) sel.selectedIndex = opts.indexOf(nonPh[0]);
  }

  function fillCheckbox(el) {
    var key = normKey(el);
    if (key.indexOf('criar') !== -1 || key.indexOf('aceito') !== -1 || key.indexOf('termo') !== -1 || key.indexOf('manter') !== -1) {
      el.checked = true;
    }
  }

  function syncRadioCards(root) {
    root.querySelectorAll('.radio-group').forEach(function (group) {
      var checked = group.querySelector('input[type="radio"]:checked');
      group.querySelectorAll('.radio-card').forEach(function (c) { c.classList.remove('active'); });
      if (checked) {
        var card = checked.closest('.radio-card');
        if (card) card.classList.add('active');
      }
    });
  }

  function fire(el) {
    el.dispatchEvent(new Event('input', { bubbles: true }));
    el.dispatchEvent(new Event('change', { bubbles: true }));
  }

  function fillRadioGroups(form) {
    var seen = {};
    var allR = form.querySelectorAll('input[type="radio"]');
    Array.prototype.forEach.call(allR, function (r) {
      var nm = r.name;
      if (!nm || seen[nm]) return;
      seen[nm] = true;
      var first = Array.prototype.find.call(allR, function (x) {
        return x.name === nm && !x.disabled;
      });
      if (first) {
        first.checked = true;
        fire(first);
      }
    });
  }

  function fillForm(form) {
    var els = form.elements;

    for (var i = 0; i < els.length; i++) {
      var el = els[i];
      if (!el || el.nodeType !== 1) continue;

      if (el.tagName === 'TEXTAREA' && !el.disabled && !el.readOnly && !el.hidden) {
        fillTextarea(el);
        fire(el);
        continue;
      }
      if (el.tagName === 'SELECT' && !el.disabled && !el.hidden) {
        if (el.multiple) {
          Array.prototype.forEach.call(el.options, function (o, idx) {
            o.selected = idx > 0 && idx <= 2 && !o.disabled;
          });
        } else {
          fillSelect(el);
        }
        fire(el);
        continue;
      }
      if (el.tagName === 'INPUT') {
        var t = (el.type || 'text').toLowerCase();
        if (t === 'radio') continue;
        if (t === 'checkbox' && !el.disabled) {
          fillCheckbox(el);
          fire(el);
          continue;
        }
        if (isFillableInput(el)) {
          fillInput(el);
          fire(el);
        }
      }
    }

    fillRadioGroups(form);
    syncRadioCards(form);
  }

  /**
   * Chamados: endereço + coordenadas (o preenchimento genérico não coloca lat/lng válidos).
   * Aplica em qualquer página que tenha estes ids (novo chamado, detalhe admin, etc.).
   */
  function fillChamadoGeoDevFields() {
    var ec = document.getElementById('endereco_completo');
    if (ec && ec.tagName === 'TEXTAREA' && !ec.disabled && !ec.readOnly) {
      ec.value =
        'Av. Paulista, 1578 - Bela Vista, São Paulo - SP, CEP 01310-200\n' +
        'Ponto de referência: teste automático (form-fill-dev).';
      fire(ec);
    }
    var la = document.getElementById('latitude');
    var lo = document.getElementById('longitude');
    if (la && !la.disabled && !la.readOnly && isFillableInput(la)) {
      la.value = '-23.5614140';
      fire(la);
    }
    if (lo && !lo.disabled && !lo.readOnly && isFillableInput(lo)) {
      lo.value = '-46.6558810';
      fire(lo);
    }
  }

  function fillAll() {
    Array.prototype.forEach.call(document.forms, function (form) {
      if (shouldSkipForm(form)) return;
      if (!formHasFillableFields(form)) return;
      fillForm(form);
    });
    fillChamadoGeoDevFields();
  }

  function pageNeedsButton() {
    for (var i = 0; i < document.forms.length; i++) {
      var f = document.forms[i];
      if (shouldSkipForm(f)) continue;
      if (formHasFillableFields(f)) return true;
    }
    return false;
  }

  function mount() {
    if (!pageNeedsButton()) return;

    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'form-fill-dev-fab';
    btn.setAttribute('aria-label', 'Preencher todos os campos com dados de teste');
    btn.textContent = 'Preencher teste';
    btn.title = 'Preenche campos visíveis dos formulários (exc. exclusão / inline).';

    btn.addEventListener('click', function () {
      fillAll();
    });

    document.body.appendChild(btn);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', mount);
  } else {
    mount();
  }
})();
