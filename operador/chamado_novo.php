<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/upload.php';
require_once __DIR__ . '/../includes/chamado_os_fields.php';
require_once __DIR__ . '/../includes/chamado_geo.php';

$user = require_auth('operador');
require_once __DIR__ . '/../includes/modules.php';
require_modulo_operador('chamados');

$pageTitle   = 'Novo pré-chamado';
$basePath    = '../';
$activePage  = 'chamados';
$operadorPwa = true;

$empresaId = operador_empresa_id($user);
$uid       = (int) ($user['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!db_ok() || $empresaId <= 0 || $uid <= 0) {
        flash_set('err', 'Banco indisponível ou conta sem empresa vinculada.');
        header('Location: chamado_novo.php');
        exit;
    }
    try {
        $cidNovo = (int) ($_POST['cliente'] ?? 0);
        if ($cidNovo <= 0 || !repo_cliente_pertence_empresa($cidNovo, $empresaId)) {
            flash_set('err', 'Selecione um cliente da sua empresa.');
            header('Location: chamado_novo.php');
            exit;
        }
        $osErros = chamado_os_validar_pre_chamado($_POST);
        if ($osErros !== []) {
            flash_set('err', implode(' ', $osErros));
            header('Location: chamado_novo.php');
            exit;
        }

        $os     = chamado_os_parse_post($_POST);
        $titulo = chamado_os_titulo_from_post($_POST);
        if ($titulo === 'Solicitação de serviço') {
            $descTitulo = trim((string) ($_POST['descricao'] ?? ''));
            $titulo = $descTitulo !== '' ? mb_substr($descTitulo, 0, 80) : 'Pré-chamado';
        }

        $id = repo_create_chamado(array_merge($os, [
            'cliente_id'          => $cidNovo,
            'criado_por_user_id'  => $uid,
            'ponto_iluminacao_id' => (int) ($_POST['ponto_iluminacao_id'] ?? 0),
            'titulo'              => $titulo,
            'descricao'           => trim((string) ($_POST['descricao'] ?? '')),
            'latitude'            => $_POST['latitude'] ?? '',
            'longitude'           => $_POST['longitude'] ?? '',
            'prioridade'          => 'Normal',
            'status'              => 'Pré-chamado',
        ]));
        if ($id === null || $id <= 0) {
            flash_set('err', 'Não foi possível abrir o pré-chamado.');
            header('Location: chamado_novo.php');
            exit;
        }

        $atr = repo_chamado_atribuir_tecnicos($id, [$uid], $empresaId);
        if (empty($atr['ok'])) {
            flash_set('err', 'Pré-chamado #' . $id . ' foi criado, mas não foi possível atribuir você como técnico. ' . (string) ($atr['err'] ?? ''));
            header('Location: chamados.php');
            exit;
        }

        $itensIds = $_POST['item_id'] ?? [];
        $itensQtd = $_POST['item_qtd'] ?? [];
        $itensMov = $_POST['item_mov'] ?? [];
        $itensObs = $_POST['item_obs'] ?? [];
        $itensOk = 0;
        $itensErros = [];
        if (is_array($itensIds)) {
            foreach ($itensIds as $i => $rawItemId) {
                $itemId = (int) $rawItemId;
                $qtdRaw = is_array($itensQtd) ? (string) ($itensQtd[$i] ?? '1') : '1';
                $qtd = (float) str_replace(',', '.', $qtdRaw);
                $mov = is_array($itensMov) ? (string) ($itensMov[$i] ?? 'utilizado') : 'utilizado';
                $obs = is_array($itensObs) ? trim((string) ($itensObs[$i] ?? '')) : '';
                if ($itemId <= 0 || $qtd <= 0) {
                    continue;
                }
                if ($mov !== 'devolvido') {
                    $mov = 'utilizado';
                }
                $rItem = repo_chamado_item_adicionar($id, $itemId, $qtd, $mov, $obs !== '' ? $obs : null);
                if (!empty($rItem['ok'])) {
                    $itensOk++;
                } else {
                    $itensErros[] = (string) ($rItem['err'] ?? 'Item não lançado.');
                }
            }
        }

        $msg       = '';
        $flashTipo = 'ok';
        $temArq    = !empty($_FILES['anexos']['name'][0]);
        if ($temArq) {
            $destino = upload_dir_chamado($id);
            $res     = upload_gravar_multiplos($_FILES['anexos'], $destino);
            $n       = 0;
            foreach ($res['salvos'] as $arq) {
                repo_create_chamado_anexo([
                    'chamado_id'    => $id,
                    'resposta_id'   => null,
                    'nome_original' => $arq['nome_original'],
                    'nome_arquivo'  => $arq['nome_arquivo'],
                    'mime'          => $arq['mime'],
                    'tamanho'       => $arq['tamanho'],
                    'enviado_por'   => $user['nome'] ?? 'Operador',
                    'enviado_tipo'  => 'operador',
                ]);
                $n++;
            }
            if ($n > 0) {
                $msg .= ' ' . $n . ' anexo(s) salvo(s).';
            }
            if (!empty($res['erros'])) {
                $msg .= ' Alguns arquivos não foram aceitos: ' . implode(' | ', $res['erros']);
                if ($n === 0) {
                    $flashTipo = 'err';
                }
            }
        }

        if ($itensOk > 0) {
            $msg .= ' ' . $itensOk . ' item(ns) lançado(s).';
        }
        if ($itensErros !== []) {
            $msg .= ' Alguns itens não foram lançados: ' . implode(' | ', $itensErros);
            $flashTipo = 'err';
        }

        $msg = 'Pré-chamado #' . $id . ' salvo.' . $msg;

        flash_set($flashTipo, trim($msg));
        header('Location: chamado_detalhe.php?id=' . $id);
        exit;
    } catch (Throwable $e) {
        flash_set('err', 'Falha ao abrir pré-chamado: ' . $e->getMessage());
        header('Location: chamado_novo.php');
        exit;
    }
}

$topTitle    = 'Novo pré-chamado';
$topSubtitle = 'Abertura incompleta. O gestor ajusta a OS depois do envio.';
$topSearch   = '';
$topAction   = ['label' => 'Voltar', 'href' => 'chamados.php', 'icon' => '←'];

$listaClientes = [];
if (db_ok() && $empresaId > 0) {
    $listaClientes = repo_clientes_na_empresa($empresaId);
}

$chamadoNovoClienteId = 0;
foreach ($listaClientes as $c) {
    $cid = (int) ($c['id'] ?? 0);
    if ($cid > 0) {
        $chamadoNovoClienteId = $cid;
        break;
    }
}
$umCliente = count($listaClientes) === 1;

$pontosIluminacaoChamado = [];
if (db_ok() && $empresaId > 0) {
    $pontosIluminacaoChamado = repo_pontos_iluminacao_list($empresaId, true, '', 'Ativo');
}

$ch_os_vals = [];
$prefillPonto = chamado_novo_aplicar_ponto_da_url($ch_os_vals, $pontosIluminacaoChamado ?: [], [
    'modo'              => 'gestao',
    'cliente_id'        => $chamadoNovoClienteId,
    'empresa_escopo_id' => $empresaId,
]);
$ch_os_vals = $prefillPonto['ch_os_vals'];
if ($prefillPonto['cliente_id'] > 0) {
    $chamadoNovoClienteId = $prefillPonto['cliente_id'];
}
$pontosIluminacaoChamado = $prefillPonto['pontos_opcoes'];

$catalogoPorCliente = [];
if (db_ok()) {
    foreach ($listaClientes as $c) {
        $cidCat = (int) ($c['id'] ?? 0);
        if ($cidCat <= 0) {
            continue;
        }
        $rowsCat = [];
        foreach (repo_cliente_itens_list($cidCat, true) as $ci) {
            $tipo = strtolower(trim((string) ($ci['tipo'] ?? '')));
            if ($tipo !== 'produto') {
                continue;
            }
            $iid = (int) ($ci['id'] ?? 0);
            if ($iid <= 0) {
                continue;
            }
            $nome = catalogo_item_nome_operador($ci);
            $cod = trim((string) ($ci['codigo'] ?? ''));
            $un = trim((string) ($ci['unidade'] ?? 'UN')) ?: 'UN';
            $rowsCat[] = [
                'id' => $iid,
                'nome' => $nome !== '' ? $nome : (string) ($ci['nome'] ?? ''),
                'codigo' => $cod,
                'unidade' => $un,
            ];
        }
        $catalogoPorCliente[$cidCat] = $rowsCat;
    }
}

$loadLeaflet = !crm_google_maps_has_api_key();
include __DIR__ . '/../includes/head.php';
?>
<div class="app">
<?php include __DIR__ . '/../includes/sidebar-operador.php'; ?>
<main class="main">
<?php include __DIR__ . '/../includes/topbar.php'; ?>

<section class="content">
  <?php if ($empresaId <= 0): ?>
  <div class="card">
    <div class="panel-body">
      <p style="color:var(--danger);font-weight:600;margin:0;">Sua conta não está vinculada a uma empresa. Peça ao gestor para definir a empresa do técnico.</p>
    </div>
  </div>
  <?php elseif ($listaClientes === []): ?>
  <div class="card">
    <div class="panel-body">
      <p style="color:var(--danger);font-weight:600;margin:0;">Nenhum cliente encontrado na sua empresa.</p>
    </div>
  </div>
  <?php else: ?>
  <form class="card" action="chamado_novo.php" method="post" enctype="multipart/form-data" autocomplete="off">
    <div class="panel-head">
      <h4>Pré-chamado</h4>
      <span class="panel-sub">Informe o local, os itens e a descrição. Origem, problema e prioridade ficam para o gestor.</span>
    </div>

    <?php if ($umCliente): ?>
      <input type="hidden" name="cliente" value="<?= (int) $chamadoNovoClienteId ?>">
    <?php else: ?>
    <div class="form form-grid">
      <div class="form-group full">
        <label for="cliente">Cliente</label>
        <select id="cliente" name="cliente" class="select" required>
          <?php foreach ($listaClientes as $c): ?>
          <option value="<?= (int) ($c['id'] ?? 0) ?>"<?= (int) ($c['id'] ?? 0) === $chamadoNovoClienteId ? ' selected' : '' ?>><?= htmlspecialchars((string) (($c['empresa'] ?? '') !== '' ? $c['empresa'] : ($c['nome'] ?? ''))) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <?php endif; ?>

    <?php
    $ch_os_descricao = '';
    $ch_os_mostrar_ponto = false;
    $ch_os_pontos_opcoes = $pontosIluminacaoChamado ?: [];
    $ch_os_mostrar_preview_mapa = true;
    $ch_os_classificacao_obrigatoria = false;
    $ch_os_ocultar_solicitante = true;
    $ch_os_botao_minha_localizacao = true;
    include __DIR__ . '/../includes/chamado_os_grid_markup.php';
    ?>

    <div class="form form-grid" style="padding-top: 0;" id="pre-itens"
         data-cliente="<?= (int) $chamadoNovoClienteId ?>"
         data-catalogo="<?= htmlspecialchars(json_encode($catalogoPorCliente, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>">
      <div class="form-group full">
        <label>Itens do atendimento</label>
        <p class="muted" style="margin:0 0 10px;font-size:13px;">Busque no catálogo e adicione o que foi utilizado ou recolhido. Dá para completar depois, no detalhe do chamado.</p>
        <?php foreach (['utilizado' => 'Utilizados', 'devolvido' => 'Recolhidos'] as $movKey => $movLabel): ?>
        <div class="pre-itens-bloco" data-mov="<?= htmlspecialchars($movKey, ENT_QUOTES, 'UTF-8') ?>" style="margin-bottom:14px;">
          <strong style="display:block;margin-bottom:8px;"><?= htmlspecialchars($movLabel) ?></strong>
          <div class="pre-item-combo">
            <input type="text" class="input pre-item-busca" autocomplete="off" placeholder="Buscar no catálogo" aria-label="Buscar item <?= htmlspecialchars($movLabel, ENT_QUOTES, 'UTF-8') ?>">
            <div class="pre-item-dd" hidden></div>
          </div>
          <div style="display:flex;gap:8px;margin-top:8px;flex-wrap:wrap;">
            <input type="text" class="input pre-item-qtd" value="1" inputmode="decimal" placeholder="Qtd" aria-label="Quantidade" style="max-width:90px;">
            <input type="text" class="input pre-item-obs" placeholder="Observação opcional" aria-label="Observação" style="flex:1;min-width:140px;">
            <button type="button" class="btn btn-secondary pre-item-add">Adicionar</button>
          </div>
          <ul class="pre-item-lista" style="list-style:none;margin:10px 0 0;padding:0;"></ul>
        </div>
        <?php endforeach; ?>
        <p class="muted pre-item-vazio-cat" style="font-size:13px;margin:0;" hidden>Nenhum produto ativo no catálogo deste cliente.</p>
      </div>
      <div class="form-group full">
        <label>Anexos</label>
        <div class="file-upload">
          <div class="file-icon">⇪</div>
          <strong>Clique ou arraste arquivos aqui</strong>
          <input type="file" name="anexos[]" multiple hidden>
        </div>
        <div class="file-list"></div>
      </div>
    </div>

    <div class="form-actions">
      <a href="chamados.php" class="btn btn-secondary">Cancelar</a>
      <button type="submit" class="btn btn-primary">Salvar pré-chamado</button>
    </div>
  </form>
  <?php endif; ?>
</section>

<style>
  .pre-item-combo{position:relative}
  .pre-item-dd{position:absolute;left:0;right:0;top:calc(100% + 4px);max-height:240px;overflow:auto;background:#fff;border:1px solid #e2e8f0;border-radius:10px;z-index:30;box-shadow:0 8px 24px rgba(15,23,42,.12)}
  .pre-item-opt{display:block;width:100%;text-align:left;padding:10px 12px;border:0;border-bottom:1px solid #f1f5f9;background:#fff;cursor:pointer;font:inherit;color:inherit}
  .pre-item-opt:hover{background:#eff6ff}
</style>
<script>
(function () {
  var root = document.getElementById('pre-itens');
  if (!root) return;
  var catalogs = {};
  try {
    catalogs = JSON.parse(root.getAttribute('data-catalogo') || '{}') || {};
  } catch (e) {
    catalogs = {};
  }
  var clienteSel = document.getElementById('cliente');
  var vazio = root.querySelector('.pre-item-vazio-cat');

  function clienteAtual() {
    if (clienteSel && clienteSel.value) return String(clienteSel.value);
    return String(root.getAttribute('data-cliente') || '');
  }

  function catalogoAtual() {
    return catalogs[clienteAtual()] || [];
  }

  function syncVazio() {
    var tem = catalogoAtual().length > 0;
    if (vazio) vazio.hidden = tem;
    root.querySelectorAll('.pre-item-busca, .pre-item-add').forEach(function (el) {
      el.disabled = !tem;
    });
  }

  function limparListas() {
    root.querySelectorAll('.pre-item-lista').forEach(function (ul) { ul.innerHTML = ''; });
    root.querySelectorAll('.pre-item-busca').forEach(function (inp) {
      inp.value = '';
      delete inp.dataset.itemId;
    });
  }

  root.querySelectorAll('.pre-itens-bloco').forEach(function (bloco) {
    var busca = bloco.querySelector('.pre-item-busca');
    var dd = bloco.querySelector('.pre-item-dd');
    var qtd = bloco.querySelector('.pre-item-qtd');
    var obs = bloco.querySelector('.pre-item-obs');
    var add = bloco.querySelector('.pre-item-add');
    var lista = bloco.querySelector('.pre-item-lista');
    var mov = bloco.getAttribute('data-mov') || 'utilizado';
    if (!busca || !dd || !add || !lista) return;

    function fechar() {
      dd.hidden = true;
      dd.innerHTML = '';
    }

    function mostrar(q) {
      var itens = catalogoAtual().filter(function (it) {
        var hay = (String(it.nome || '') + ' ' + String(it.codigo || '') + ' ' + String(it.unidade || '')).toLowerCase();
        return q === '' || hay.indexOf(q) !== -1;
      }).slice(0, 40);
      dd.innerHTML = '';
      if (!itens.length) {
        fechar();
        return;
      }
      itens.forEach(function (it) {
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'pre-item-opt';
        b.textContent = it.nome + (it.codigo ? ' · ' + it.codigo : '') + (it.unidade ? ' · ' + it.unidade : '');
        b.addEventListener('mousedown', function (ev) {
          ev.preventDefault();
          busca.value = it.nome;
          busca.dataset.itemId = String(it.id);
          busca.dataset.itemNome = it.nome;
          fechar();
        });
        dd.appendChild(b);
      });
      dd.hidden = false;
    }

    busca.addEventListener('input', function () {
      delete busca.dataset.itemId;
      mostrar(busca.value.trim().toLowerCase());
    });
    busca.addEventListener('focus', function () {
      mostrar(busca.value.trim().toLowerCase());
    });
    busca.addEventListener('blur', function () {
      window.setTimeout(fechar, 160);
    });

    add.addEventListener('click', function () {
      var itemId = parseInt(busca.dataset.itemId || '0', 10);
      if (!itemId) {
        busca.focus();
        mostrar(busca.value.trim().toLowerCase());
        return;
      }
      var nome = busca.dataset.itemNome || busca.value;
      var q = (qtd && qtd.value ? qtd.value : '1').trim() || '1';
      var o = obs && obs.value ? obs.value.trim() : '';
      var li = document.createElement('li');
      li.style.cssText = 'display:flex;gap:8px;align-items:center;padding:8px 0;border-bottom:1px solid var(--border,#e2e8f0);';
      var label = document.createElement('span');
      label.style.flex = '1';
      label.textContent = nome + ' × ' + q + (o ? ' — ' + o : '');
      var rm = document.createElement('button');
      rm.type = 'button';
      rm.className = 'btn btn-secondary';
      rm.textContent = 'Remover';
      rm.addEventListener('click', function () { li.remove(); });
      function hidden(name, value) {
        var inp = document.createElement('input');
        inp.type = 'hidden';
        inp.name = name;
        inp.value = value;
        li.appendChild(inp);
      }
      li.appendChild(label);
      li.appendChild(rm);
      hidden('item_id[]', String(itemId));
      hidden('item_qtd[]', q);
      hidden('item_mov[]', mov);
      hidden('item_obs[]', o);
      lista.appendChild(li);
      busca.value = '';
      delete busca.dataset.itemId;
      if (qtd) qtd.value = '1';
      if (obs) obs.value = '';
    });
  });

  if (clienteSel) {
    clienteSel.addEventListener('change', function () {
      limparListas();
      syncVazio();
    });
  }
  syncVazio();
})();
</script>
<script>
(function () {
  var btn = document.getElementById('os-btn-minha-localizacao');
  var statusEl = document.getElementById('os-minha-localizacao-status');
  if (!btn) return;

  var UF_POR_NOME = {
    acre: 'AC', alagoas: 'AL', amapa: 'AP', amazonas: 'AM', bahia: 'BA', ceara: 'CE',
    'distrito federal': 'DF', 'espirito santo': 'ES', goias: 'GO', maranhao: 'MA',
    'mato grosso': 'MT', 'mato grosso do sul': 'MS', 'minas gerais': 'MG', para: 'PA',
    paraiba: 'PB', parana: 'PR', pernambuco: 'PE', piaui: 'PI', 'rio de janeiro': 'RJ',
    'rio grande do norte': 'RN', 'rio grande do sul': 'RS', rondonia: 'RO', roraima: 'RR',
    'santa catarina': 'SC', 'sao paulo': 'SP', sergipe: 'SE', tocantins: 'TO'
  };

  function setStatus(text) {
    if (!statusEl) return;
    statusEl.hidden = !text;
    statusEl.textContent = text || '';
  }

  function setField(id, value) {
    var el = document.getElementById(id);
    if (!el) return;
    el.value = value;
    el.dispatchEvent(new Event('input', { bubbles: true }));
    el.dispatchEvent(new Event('change', { bubbles: true }));
  }

  function semAcento(s) {
    return String(s || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().trim();
  }

  function ufDe(addr) {
    var iso = String(addr['ISO3166-2-lvl4'] || '');
    var partes = iso.split('-');
    if (partes.length === 2 && partes[1].length === 2) return partes[1].toUpperCase();
    var st = String(addr.state || '').trim();
    if (st.length === 2) return st.toUpperCase();
    return UF_POR_NOME[semAcento(st)] || '';
  }

  function preencherEndereco(addr) {
    var road = addr.road || addr.pedestrian || addr.residential || addr.footway || '';
    var cidade = addr.city || addr.town || addr.village || addr.municipality || '';
    var bairro = addr.suburb || addr.neighbourhood || addr.city_district || '';
    var cep = String(addr.postcode || '').replace(/\D/g, '').slice(0, 8);
    if (cep.length === 8) cep = cep.slice(0, 5) + '-' + cep.slice(5);
    if (road) setField('os_logradouro', road);
    if (addr.house_number) setField('os_numero', String(addr.house_number));
    if (bairro) setField('os_bairro', bairro);
    if (cidade) setField('os_cidade', cidade);
    var uf = ufDe(addr);
    if (uf) setField('os_uf', uf);
    if (cep) setField('os_cep', cep);
  }

  btn.addEventListener('click', function () {
    if (!navigator.geolocation) {
      setStatus('Este navegador não informa a localização.');
      return;
    }
    btn.disabled = true;
    setStatus('Obtendo sua localização…');
    navigator.geolocation.getCurrentPosition(function (pos) {
      var lat = pos.coords.latitude;
      var lng = pos.coords.longitude;
      setField('chamado_latitude', lat.toFixed(7));
      setField('chamado_longitude', lng.toFixed(7));
      setStatus('Localização obtida. Buscando o endereço…');
      var url = 'geocode_nominatim_api.php?action=reverse&lat='
        + encodeURIComponent(String(lat))
        + '&lon=' + encodeURIComponent(String(lng));
      fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (data) {
          if (data && data.ok && data.address) {
            preencherEndereco(data.address);
            setStatus('Endereço preenchido com a sua localização. Confira os campos antes de salvar.');
          } else {
            setStatus('Coordenadas salvas. Não foi possível completar o endereço — preencha o que faltar.');
          }
        })
        .catch(function () {
          setStatus('Coordenadas salvas. Não foi possível completar o endereço — preencha o que faltar.');
        })
        .then(function () { btn.disabled = false; });
    }, function () {
      btn.disabled = false;
      setStatus('Permita o acesso à localização para preencher o endereço.');
    }, { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 });
  });
})();
</script>
<script>
(function () {
  var input = document.querySelector('.file-upload input[type="file"][name="anexos[]"]');
  var list = document.querySelector('.file-list');
  if (!input || !list) return;
  input.addEventListener('change', function () {
    list.innerHTML = '';
    Array.prototype.forEach.call(input.files || [], function (file) {
      var row = document.createElement('div');
      row.className = 'file-preview-row';
      row.style.cssText = 'display:flex;align-items:center;gap:10px;margin-top:8px;padding:8px;border:1px solid var(--border,#e2e8f0);border-radius:10px;';
      if ((file.type || '').indexOf('image/') === 0) {
        var img = document.createElement('img');
        img.style.cssText = 'width:56px;height:56px;object-fit:cover;border-radius:8px;';
        img.src = URL.createObjectURL(file);
        img.onload = function () { URL.revokeObjectURL(img.src); };
        row.appendChild(img);
      }
      var meta = document.createElement('span');
      meta.textContent = file.name + ' (' + Math.round(file.size / 1024) + ' KB)';
      row.appendChild(meta);
      list.appendChild(row);
    });
  });
})();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
