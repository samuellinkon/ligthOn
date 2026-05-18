/**
 * Composer de resposta do chamado.
 *  - + Anexo : dispara input file (multiple) e mostra preview.
 *  - Interna : toggle. Quando ativo, a resposta é salva como nota interna.
 *  - Submit  : permite enviar com texto vazio se houver arquivo selecionado.
 */
(function () {
  const form      = document.getElementById('composer-form');
  if (!form) return;

  const textarea  = document.getElementById('composer-text');
  const fileInput = document.getElementById('composer-file-input');
  const filesBox  = document.getElementById('composer-files');
  const filesList = document.getElementById('composer-files-list');
  const btnAnexo  = document.getElementById('btn-anexo');
  const btnModelo = document.getElementById('btn-modelo');
  const menuModelo = document.getElementById('menu-modelo');
  const btnInterna = document.getElementById('btn-interna');
  const inputInterna = document.getElementById('composer-interna');
  const btnEnviar = document.getElementById('btn-enviar');

  // ------------------------------------------------------------
  // + ANEXO
  // ------------------------------------------------------------
  if (btnAnexo && fileInput) {
    btnAnexo.addEventListener('click', () => fileInput.click());
  }

  if (fileInput) {
  fileInput.addEventListener('change', () => {
    filesList.innerHTML = '';
    if (!fileInput.files || fileInput.files.length === 0) {
      filesBox.hidden = true;
      return;
    }
    filesBox.hidden = false;
    for (const f of fileInput.files) {
      const li = document.createElement('li');
      li.textContent = `${f.name} (${formatSize(f.size)})`;
      filesList.appendChild(li);
    }
  });
  }

  function formatSize(bytes) {
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1048576) return (bytes/1024).toFixed(1).replace('.', ',') + ' KB';
    if (bytes < 1073741824) return (bytes/1048576).toFixed(1).replace('.', ',') + ' MB';
    return (bytes/1073741824).toFixed(2).replace('.', ',') + ' GB';
  }

  // ------------------------------------------------------------
  // MODELO
  // ------------------------------------------------------------
  if (btnModelo && menuModelo) {
    // Começa fechado (remove qualquer resquício de "open" e mantém hidden)
    menuModelo.classList.remove('open');
    menuModelo.hidden = true;

    const openMenu = () => {
      menuModelo.hidden = false;
      menuModelo.classList.add('open');
    };
    const closeMenu = () => {
      menuModelo.classList.remove('open');
      menuModelo.hidden = true;
    };

    btnModelo.addEventListener('click', (e) => {
      e.stopPropagation();
      if (menuModelo.classList.contains('open')) closeMenu();
      else openMenu();
    });

    document.addEventListener('click', (e) => {
      if (!menuModelo.contains(e.target) && e.target !== btnModelo) {
        closeMenu();
      }
    });

    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') closeMenu();
    });

    menuModelo.querySelectorAll('.tool-menu-item').forEach(item => {
      item.addEventListener('click', () => {
        const corpo      = item.dataset.corpo || '';
        const cliente    = item.dataset.cliente || '';
        const responsav  = item.dataset.responsavel || '';
        const texto = corpo
          .replace(/{{cliente}}/g, cliente)
          .replace(/{{responsavel}}/g, responsav);
        textarea.value = texto;
        textarea.focus();
        closeMenu();
      });
    });
  }

  // ------------------------------------------------------------
  // INTERNA
  // ------------------------------------------------------------
  if (btnInterna) {
    btnInterna.addEventListener('click', () => {
      const isAtivo = btnInterna.classList.toggle('active');
      inputInterna.value = isAtivo ? '1' : '';
      if (isAtivo) {
        textarea.classList.add('textarea-interna');
        textarea.placeholder = 'Nota interna — só admins verão esta mensagem.';
        btnEnviar.textContent = 'Salvar nota interna';
      } else {
        textarea.classList.remove('textarea-interna');
        textarea.placeholder = 'Escreva uma resposta para o cliente...';
        btnEnviar.textContent = 'Enviar resposta';
      }
    });
  }

  // ------------------------------------------------------------
  // Submit: permitir enviar só com arquivo (sem texto)
  // ------------------------------------------------------------
  form.addEventListener('submit', (e) => {
    const texto = (textarea.value || '').trim();
    const temArq = fileInput && fileInput.files && fileInput.files.length > 0;
    if (!texto && !temArq) {
      e.preventDefault();
      if (typeof window.appAlert === 'function') {
        window.appAlert('Escreva uma resposta ou selecione ao menos um anexo.', 'Enviar resposta').then(function () {
          textarea.focus();
        });
      } else {
        textarea.focus();
      }
    }
  });
})();
