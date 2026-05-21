/**
 * Máscaras de entrada: telefone, CPF/CNPJ, CEP e normalização de e-mail.
 * Uso: data-crm-mask="telefone|cpf|cnpj|cpf-cnpj|cep|email" em <input>.
 */
(function () {
  'use strict';

  function digits(s, max) {
    return String(s == null ? '' : s).replace(/\D/g, '').slice(0, max);
  }

  function maskTelefoneBr(val) {
    var d = digits(val, 11);
    if (!d.length) return '';
    if (d.length <= 2) return '(' + d;
    if (d.length <= 6) return '(' + d.slice(0, 2) + ') ' + d.slice(2);
    if (d.length <= 10) {
      return '(' + d.slice(0, 2) + ') ' + d.slice(2, 6) + '-' + d.slice(6);
    }
    return '(' + d.slice(0, 2) + ') ' + d.slice(2, 7) + '-' + d.slice(7, 11);
  }

  function maskCpf(val) {
    var d = digits(val, 11);
    if (!d.length) return '';
    if (d.length <= 3) return d;
    if (d.length <= 6) return d.slice(0, 3) + '.' + d.slice(3);
    if (d.length <= 9) return d.slice(0, 3) + '.' + d.slice(3, 6) + '.' + d.slice(6);
    return d.slice(0, 3) + '.' + d.slice(3, 6) + '.' + d.slice(6, 9) + '-' + d.slice(9);
  }

  function maskCpfCnpj(val) {
    var d = digits(val, 14);
    if (!d.length) return '';
    if (d.length <= 3) return d;
    if (d.length <= 6) return d.slice(0, 3) + '.' + d.slice(3);
    if (d.length <= 9) return d.slice(0, 3) + '.' + d.slice(3, 6) + '.' + d.slice(6);
    if (d.length <= 11) {
      return d.slice(0, 3) + '.' + d.slice(3, 6) + '.' + d.slice(6, 9) + '-' + d.slice(9);
    }
    if (d.length <= 12) {
      return d.slice(0, 2) + '.' + d.slice(2, 5) + '.' + d.slice(5, 8) + '/' + d.slice(8, 12);
    }
    return d.slice(0, 2) + '.' + d.slice(2, 5) + '.' + d.slice(5, 8) + '/' + d.slice(8, 12) + '-' + d.slice(12, 14);
  }

  function maskCep(val) {
    var d = digits(val, 8);
    if (d.length <= 5) return d;
    return d.slice(0, 5) + '-' + d.slice(5);
  }

  function normalizeEmail(val, onBlur) {
    var s = String(val == null ? '' : val).replace(/\s/g, '');
    if (onBlur) {
      s = s.trim().toLowerCase();
    }
    return s;
  }

  var MASK_FNS = {
    telefone: maskTelefoneBr,
    cpf: maskCpf,
    cnpj: maskCpfCnpj,
    'cpf-cnpj': maskCpfCnpj,
    cep: maskCep,
    email: function (val, onBlur) {
      return normalizeEmail(val, onBlur);
    },
  };

  /** Exportado para crm-viacep.js */
  window.crmMaskFormatCep = maskCep;
  window.crmMaskCepDigits = function (val) {
    return digits(val, 8);
  };

  function applyMask(input, onBlur) {
    var kind = (input.getAttribute('data-crm-mask') || '').toLowerCase().trim();
    var fn = MASK_FNS[kind];
    if (!fn) return;
    var masked = fn(input.value, onBlur);
    if (input.value !== masked) {
      input.value = masked;
    }
  }

  function wireMask(input) {
    if (!input || input.getAttribute('data-crm-mask-wired') === '1') return;
    input.setAttribute('data-crm-mask-wired', '1');

    var kind = (input.getAttribute('data-crm-mask') || '').toLowerCase().trim();
    if (!MASK_FNS[kind]) return;

    function onInput() {
      applyMask(input, false);
    }
    function onBlur() {
      applyMask(input, true);
    }

    input.addEventListener('input', onInput);
    input.addEventListener('blur', onBlur);
    applyMask(input, true);
  }

  function initAll(root) {
    var scope = root || document;
    scope.querySelectorAll('[data-crm-mask]').forEach(wireMask);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () {
      initAll(document);
    });
  } else {
    initAll(document);
  }

  window.crmInputMasksInit = initAll;
})();
