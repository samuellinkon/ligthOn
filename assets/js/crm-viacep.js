/**
 * Preenche logradouro, bairro, cidade e UF a partir do CEP (ViaCEP).
 * Espera #os_cep e campos opcionais #os_logradouro, #os_bairro, #os_cidade, #os_uf, #os_complemento.
 */
(function () {
  var cepEl = document.getElementById('os_cep');
  if (!cepEl) return;

  var logra = document.getElementById('os_logradouro');
  var bairro = document.getElementById('os_bairro');
  var cidade = document.getElementById('os_cidade');
  var uf = document.getElementById('os_uf');
  var comp = document.getElementById('os_complemento');

  var debounceTimer = null;
  var fetchAbort = null;
  var reqSeq = 0;

  function digitsOnly(s) {
    return String(s || '').replace(/\D/g, '').slice(0, 8);
  }

  function formatCepDisplay(d) {
    if (d.length <= 5) return d;
    return d.slice(0, 5) + '-' + d.slice(5);
  }

  function applyViaCep(data) {
    if (logra) logra.value = data.logradouro || '';
    if (bairro) bairro.value = data.bairro || '';
    if (cidade) cidade.value = data.localidade || '';
    if (uf) uf.value = String(data.uf || '').toUpperCase().slice(0, 2);
    if (comp && data.complemento && String(comp.value).trim() === '') {
      comp.value = data.complemento;
    }
  }

  function clearLoading(token) {
    if (token !== reqSeq) return;
    cepEl.removeAttribute('aria-busy');
    cepEl.classList.remove('input--cep-loading');
  }

  function runLookup(digits) {
    if (digits.length !== 8) return;

    var token = ++reqSeq;
    if (fetchAbort) fetchAbort.abort();
    fetchAbort = new AbortController();

    cepEl.setAttribute('aria-busy', 'true');
    cepEl.classList.add('input--cep-loading');

    fetch('https://viacep.com.br/ws/' + digits + '/json/', {
      signal: fetchAbort.signal,
      credentials: 'omit',
      headers: { Accept: 'application/json' },
    })
      .then(function (res) {
        if (!res.ok) throw new Error('HTTP');
        return res.json();
      })
      .then(function (data) {
        if (token !== reqSeq) return;
        if (data.erro) {
          if (typeof window.appAlert === 'function') {
            window.appAlert('CEP não encontrado. Confira os números.', 'CEP');
          }
          return;
        }
        applyViaCep(data);
      })
      .catch(function (err) {
        if (err && err.name === 'AbortError') return;
        if (token !== reqSeq) return;
        if (typeof window.appAlert === 'function') {
          window.appAlert('Não foi possível consultar o CEP. Verifique a conexão e tente de novo.', 'CEP');
        }
      })
      .finally(function () {
        clearLoading(token);
      });
  }

  function scheduleDebouncedLookup(digits) {
    clearTimeout(debounceTimer);
    if (digits.length !== 8) return;
    debounceTimer = setTimeout(function () {
      runLookup(digits);
    }, 450);
  }

  cepEl.addEventListener('input', function () {
    var d = digitsOnly(cepEl.value);
    cepEl.value = formatCepDisplay(d);
    clearTimeout(debounceTimer);
    scheduleDebouncedLookup(d);
  });

  cepEl.addEventListener('blur', function () {
    clearTimeout(debounceTimer);
    var d = digitsOnly(cepEl.value);
    if (d.length === 8) runLookup(d);
  });
})();
