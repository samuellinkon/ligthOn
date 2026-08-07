<?php
/**
 * Gera PDF: gatilhos e notificações por perfil no fluxo de chamado.
 *
 * Uso:
 *   php scripts/gerar_doc_notificacoes_fluxo.php
 *   → docs/fluxo-notificacoes-chamado.pdf
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/vendor/autoload.php';
require_once $root . '/includes/chamados_periodo_pdf_dompdf.php';

$outDir = $root . '/docs';
if (!is_dir($outDir) && !mkdir($outDir, 0775, true) && !is_dir($outDir)) {
    fwrite(STDERR, "Não foi possível criar docs/\n");
    exit(1);
}
$outFile = $outDir . '/fluxo-notificacoes-chamado.pdf';
$geradoEm = (new DateTimeImmutable('now', new DateTimeZone('America/Recife')))->format('d/m/Y H:i');

$html = <<<'HTML'
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<style>
  @page { margin: 28mm 18mm 22mm 18mm; }
  body {
    font-family: DejaVu Sans, sans-serif;
    font-size: 10.5pt;
    color: #1e293b;
    line-height: 1.45;
  }
  h1 {
    font-size: 18pt;
    color: #312e81;
    margin: 0 0 4px;
    border-bottom: 3px solid #534ab7;
    padding-bottom: 8px;
  }
  h2 {
    font-size: 12.5pt;
    color: #3730a3;
    margin: 22px 0 8px;
    page-break-after: avoid;
  }
  h3 {
    font-size: 11pt;
    color: #4338ca;
    margin: 14px 0 6px;
    page-break-after: avoid;
  }
  .meta {
    color: #64748b;
    font-size: 9pt;
    margin-bottom: 16px;
  }
  .lead {
    background: #f5f3ff;
    border-left: 4px solid #534ab7;
    padding: 10px 12px;
    margin: 0 0 16px;
    font-size: 10pt;
  }
  table {
    width: 100%;
    border-collapse: collapse;
    margin: 8px 0 14px;
    font-size: 8.5pt;
    page-break-inside: avoid;
  }
  th, td {
    border: 1px solid #cbd5e1;
    padding: 6px 7px;
    vertical-align: top;
    text-align: left;
  }
  th {
    background: #534ab7;
    color: #fff;
    font-weight: 700;
  }
  tr:nth-child(even) td { background: #f8fafc; }
  .sim { color: #15803d; font-weight: 700; }
  .nao { color: #94a3b8; }
  .autor { color: #b45309; font-weight: 600; }
  .badge {
    display: inline-block;
    background: #e0e7ff;
    color: #3730a3;
    border-radius: 4px;
    padding: 1px 6px;
    font-size: 8pt;
    font-weight: 700;
  }
  .step {
    border: 1px solid #e2e8f0;
    border-radius: 6px;
    padding: 10px 12px;
    margin: 0 0 10px;
    page-break-inside: avoid;
    background: #fff;
  }
  .step-num {
    display: inline-block;
    width: 22px;
    height: 22px;
    line-height: 22px;
    text-align: center;
    border-radius: 50%;
    background: #534ab7;
    color: #fff;
    font-size: 9pt;
    font-weight: 700;
    margin-right: 6px;
  }
  .step strong.titulo { color: #1e293b; }
  ul { margin: 4px 0 8px 18px; padding: 0; }
  li { margin-bottom: 3px; }
  .note {
    font-size: 9pt;
    color: #64748b;
    margin-top: 4px;
  }
  .footer-note {
    margin-top: 24px;
    padding-top: 10px;
    border-top: 1px solid #e2e8f0;
    font-size: 8.5pt;
    color: #64748b;
  }
  code {
    font-family: DejaVu Sans Mono, monospace;
    font-size: 8pt;
    background: #f1f5f9;
    padding: 1px 4px;
    border-radius: 3px;
  }
</style>
</head>
<body>

<h1>Fluxo de notificações do chamado</h1>
<p class="meta">CRM Prefeitura / OnLight · Documento de gatilhos e destinatários por perfil · Gerado em __GERADO_EM__</p>

<div class="lead">
  Este documento descreve <strong>quando</strong> cada notificação é disparada no ciclo de um chamado,
  <strong>quem recebe</strong> (Admin, Gestor, Cliente, Técnico/Operador) e o <strong>texto típico</strong> exibido.
  O autor da ação <em>nunca</em> recebe a própria notificação.
</div>

<h2>1. Papéis (roles)</h2>
<table>
  <thead>
    <tr>
      <th style="width:18%">Perfil</th>
      <th style="width:22%">Acesso</th>
      <th>O que vê nas notificações</th>
    </tr>
  </thead>
  <tbody>
    <tr>
      <td><strong>Admin</strong></td>
      <td>Painel interno</td>
      <td>Ciclo completo do chamado (criação, finalização do técnico, aprovação, validação, mensagens internas, reabertura). Filtros: Todas / Aberto / Atendido por técnico / Validado / Aprovado.</td>
    </tr>
    <tr>
      <td><strong>Gestor</strong></td>
      <td>Painel interno (empresa)</td>
      <td>Mesmo ciclo do admin, restrito à empresa. Mesmos filtros de status.</td>
    </tr>
    <tr>
      <td><strong>Cliente</strong></td>
      <td>Portal do cliente</td>
      <td>Abertura do chamado, técnico designado, aprovação do gestor, mensagens públicas, validação (outros usuários cliente). Filtros de status iguais aos da gestão.</td>
    </tr>
    <tr>
      <td><strong>Técnico (Operador)</strong></td>
      <td>App / painel operador</td>
      <td><strong>Somente</strong> “Chamado atribuído a você”. Sem filtros de status — listagem simplificada.</td>
    </tr>
  </tbody>
</table>

<h2>2. Fluxo principal do chamado</h2>

<div class="step">
  <span class="step-num">1</span>
  <strong class="titulo">Chamado criado</strong>
  <span class="badge">chamado_criado</span>
  <p class="note"><strong>Gatilho:</strong> criação do chamado (<code>notificar_chamado_criado</code>).</p>
  <p><strong>Título:</strong> Novo chamado #ID<br>
  <strong>Descrição:</strong> título do chamado / prioridade.</p>
  <ul>
    <li><span class="sim">Recebe:</span> Admin, Gestor da empresa, Cliente da empresa</li>
    <li><span class="nao">Não recebe:</span> Técnico</li>
    <li><span class="autor">Exceção:</span> se o cliente abre o chamado, ele (autor) não é notificado</li>
  </ul>
</div>

<div class="step">
  <span class="step-num">2</span>
  <strong class="titulo">Técnico atribuído</strong>
  <span class="badge">chamado_tecnico_atribuido</span>
  <span class="badge">chamado_em_atendimento</span>
  <p class="note"><strong>Gatilho:</strong> atribuição de técnico(s) novos ao chamado (<code>repo_chamado_atribuir_tecnicos</code>).</p>
  <table>
    <thead>
      <tr><th>Destinatário</th><th>Tipo</th><th>Título / mensagem</th></tr>
    </thead>
    <tbody>
      <tr>
        <td>Técnico(s) recém-atribuído(s)</td>
        <td><code>chamado_tecnico_atribuido</code></td>
        <td>Chamado #ID atribuído a você — “Abra para iniciar o atendimento.”</td>
      </tr>
      <tr>
        <td>Cliente da empresa</td>
        <td><code>chamado_em_atendimento</code></td>
        <td>Chamado #ID em atendimento — “Técnico designado: Nome…”</td>
      </tr>
      <tr>
        <td>Admin / Gestor</td>
        <td colspan="2"><span class="nao">Não notificados neste evento</span></td>
      </tr>
    </tbody>
  </table>
</div>

<div class="step">
  <span class="step-num">3</span>
  <strong class="titulo">Técnico finaliza atendimento</strong>
  <span class="badge">chamado_finalizado_tecnico</span>
  <p class="note"><strong>Gatilho:</strong> operador envia OS / finaliza (<code>repo_operador_chamado_finalizar</code>) → status <em>Aguardando Aprovação</em>.</p>
  <p><strong>Título:</strong> Chamado #ID finalizado pelo técnico<br>
  <strong>Descrição:</strong> aguarda aprovação do gestor.</p>
  <ul>
    <li><span class="sim">Recebe:</span> Admin, Gestor</li>
    <li><span class="nao">Não recebe:</span> Cliente, Técnico</li>
  </ul>
</div>

<div class="step">
  <span class="step-num">4</span>
  <strong class="titulo">Gestor aprova / marca Resolvido</strong>
  <span class="badge">chamado_aprovado_gestor</span>
  <p class="note"><strong>Gatilhos:</strong></p>
  <ul>
    <li>Fluxo formal <code>repo_chamado_aprovar_gestor</code> (checklist / aprovação)</li>
    <li>Alteração de status para <strong>Resolvido</strong> no dropdown (<code>repo_update_chamado_status</code>)</li>
  </ul>
  <p><strong>Título:</strong> Chamado #ID aprovado pelo gestor<br>
  <strong>Descrição:</strong> aguarda validação do cliente.</p>
  <ul>
    <li><span class="sim">Recebe:</span> Cliente, Admin (e demais gestores da empresa, exceto o autor)</li>
    <li><span class="nao">Não recebe:</span> Técnico; o gestor que aprovou (autor)</li>
  </ul>
</div>

<div class="step">
  <span class="step-num">5</span>
  <strong class="titulo">Cliente valida</strong>
  <span class="badge">chamado_validado_cliente</span>
  <p class="note"><strong>Gatilho:</strong> status alterado para <strong>Validado</strong> (<code>repo_update_chamado_status</code>).</p>
  <p><strong>Título:</strong> Chamado #ID validado pelo cliente<br>
  <strong>Descrição:</strong> O cliente validou o chamado.</p>
  <ul>
    <li><span class="sim">Recebe:</span> Admin, Gestor (e outros usuários cliente da empresa, se houver)</li>
    <li><span class="nao">Não recebe:</span> Técnico; o cliente que validou (autor)</li>
  </ul>
</div>

<div class="step">
  <span class="step-num">6</span>
  <strong class="titulo">Cliente reabre</strong>
  <span class="badge">chamado_reaberto</span>
  <p class="note"><strong>Gatilho:</strong> reabertura pelo cliente (<code>repo_chamado_cliente_reabrir</code>) → status <em>Aberto</em>.</p>
  <p><strong>Título:</strong> Chamado #ID reaberto pelo cliente</p>
  <ul>
    <li><span class="sim">Recebe:</span> Admin, Gestor</li>
    <li><span class="nao">Não recebe:</span> Cliente (autor), Técnico</li>
  </ul>
</div>

<div class="step">
  <span class="step-num">7</span>
  <strong class="titulo">Nova mensagem no chamado</strong>
  <span class="badge">chamado_mensagem</span>
  <p class="note"><strong>Gatilho:</strong> resposta/mensagem gravada (<code>criarNotificacoesChamado</code>).</p>
  <ul>
    <li><strong>Mensagem interna</strong> → só Admin e Gestor</li>
    <li><strong>Mensagem pública</strong> → Admin, Gestor e Cliente</li>
    <li><span class="nao">Técnico</span> não recebe mensagens no ciclo atual (só atribuição)</li>
    <li>Autor da mensagem nunca é notificado</li>
  </ul>
</div>

<h2>3. Matriz resumo (quem recebe o quê)</h2>
<table>
  <thead>
    <tr>
      <th>Evento / gatilho</th>
      <th>Admin</th>
      <th>Gestor</th>
      <th>Cliente</th>
      <th>Técnico</th>
    </tr>
  </thead>
  <tbody>
    <tr>
      <td>Chamado criado</td>
      <td class="sim">Sim</td>
      <td class="sim">Sim</td>
      <td class="sim">Sim*</td>
      <td class="nao">Não</td>
    </tr>
    <tr>
      <td>Técnico atribuído</td>
      <td class="nao">Não</td>
      <td class="nao">Não</td>
      <td class="sim">Sim<br><small>em atendimento</small></td>
      <td class="sim">Sim<br><small>atribuído a você</small></td>
    </tr>
    <tr>
      <td>Técnico finalizou (Aguardando Aprovação)</td>
      <td class="sim">Sim</td>
      <td class="sim">Sim</td>
      <td class="nao">Não</td>
      <td class="nao">Não</td>
    </tr>
    <tr>
      <td>Gestor → Resolvido / aprovou</td>
      <td class="sim">Sim*</td>
      <td class="sim">Sim*</td>
      <td class="sim">Sim</td>
      <td class="nao">Não</td>
    </tr>
    <tr>
      <td>Cliente → Validado</td>
      <td class="sim">Sim</td>
      <td class="sim">Sim</td>
      <td class="sim">Sim*</td>
      <td class="nao">Não</td>
    </tr>
    <tr>
      <td>Cliente reabriu</td>
      <td class="sim">Sim</td>
      <td class="sim">Sim</td>
      <td class="nao">Não</td>
      <td class="nao">Não</td>
    </tr>
    <tr>
      <td>Mensagem pública</td>
      <td class="sim">Sim*</td>
      <td class="sim">Sim*</td>
      <td class="sim">Sim*</td>
      <td class="nao">Não</td>
    </tr>
    <tr>
      <td>Mensagem interna</td>
      <td class="sim">Sim*</td>
      <td class="sim">Sim*</td>
      <td class="nao">Não</td>
      <td class="nao">Não</td>
    </tr>
  </tbody>
</table>
<p class="note">* Exceto o autor da ação.</p>

<h2>4. Filtros na interface</h2>
<table>
  <thead>
    <tr>
      <th>Filtro</th>
      <th>Tipos / títulos correspondentes</th>
      <th>Quem usa</th>
    </tr>
  </thead>
  <tbody>
    <tr>
      <td>Todas</td>
      <td>Todas as notificações do usuário (lidas e não lidas)</td>
      <td>Admin, Gestor, Cliente</td>
    </tr>
    <tr>
      <td>Aberto</td>
      <td><code>chamado_criado</code> / “Novo chamado #…”</td>
      <td>Admin, Gestor, Cliente</td>
    </tr>
    <tr>
      <td>Atendido por técnico</td>
      <td><code>chamado_em_atendimento</code>, <code>chamado_finalizado_tecnico</code>, títulos com “em atendimento”</td>
      <td>Admin, Gestor, Cliente</td>
    </tr>
    <tr>
      <td>Validado</td>
      <td><code>chamado_validado_cliente</code> / títulos com “validado”</td>
      <td>Admin, Gestor, Cliente</td>
    </tr>
    <tr>
      <td>Aprovado</td>
      <td><code>chamado_aprovado_gestor</code> / “aprovado pelo gestor” / “foi resolvido”</td>
      <td>Admin, Gestor, Cliente</td>
    </tr>
    <tr>
      <td>—</td>
      <td>Sem filtros — lista só atribuições</td>
      <td><strong>Técnico</strong></td>
    </tr>
  </tbody>
</table>

<h2>5. Diagrama do fluxo (ordem típica)</h2>
<p style="font-family: DejaVu Sans Mono, monospace; font-size: 8.5pt; background:#f8fafc; border:1px solid #e2e8f0; padding:12px; line-height:1.55;">
Aberto<br>
&nbsp;&nbsp;│&nbsp; notifica: Admin · Gestor · Cliente<br>
&nbsp;&nbsp;▼<br>
Em andamento&nbsp;&nbsp;<span style="color:#64748b">(técnico atribuído)</span><br>
&nbsp;&nbsp;│&nbsp; notifica: Técnico + Cliente<br>
&nbsp;&nbsp;▼<br>
Aguardando Aprovação&nbsp;&nbsp;<span style="color:#64748b">(técnico finalizou)</span><br>
&nbsp;&nbsp;│&nbsp; notifica: Admin · Gestor<br>
&nbsp;&nbsp;▼<br>
Resolvido&nbsp;&nbsp;<span style="color:#64748b">(gestor aprovou)</span><br>
&nbsp;&nbsp;│&nbsp; notifica: Cliente · Admin/Gestor<br>
&nbsp;&nbsp;▼<br>
Validado&nbsp;&nbsp;<span style="color:#64748b">(cliente confirmou)</span><br>
&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;notifica: Admin · Gestor<br>
<br>
<span style="color:#64748b">← Reabertura pelo cliente volta para Aberto (notifica gestão)</span>
</p>

<h2>6. Observações técnicas</h2>
<ul>
  <li>Funções principais: <code>includes/notificacoes.php</code>.</li>
  <li>Destinatários: <code>repo_notificacao_destinatarios_chamado</code> (gestão / gestão+cliente) e <code>repo_notificacao_destinatarios_clientes_chamado</code>.</li>
  <li>Operadores foram removidos do ciclo geral; recebem apenas atribuição.</li>
  <li>Medição BM (<code>medicao_bm_importado</code>) é um fluxo paralelo (gestores + portal cliente), fora do ciclo do chamado operacional descrito acima.</li>
</ul>

<div class="footer-note">
  OnLight / CRM Prefeitura — documentação gerada automaticamente a partir do comportamento atual do sistema.
  Em caso de alteração de regras de negócio, regenerar este PDF com
  <code>php scripts/gerar_doc_notificacoes_fluxo.php</code>.
</div>

</body>
</html>
HTML;

$html = str_replace('__GERADO_EM__', $geradoEm, $html);

$options = new \Dompdf\Options();
$options->set('isHtml5ParserEnabled', true);
$options->set('defaultFont', 'DejaVu Sans');
$options->set('dpi', 96);
$options->set('isPhpEnabled', false);
chamados_dompdf_apply_writable_options($options);

$dompdf = new \Dompdf\Dompdf($options);
$dompdf->loadHtml(chamados_dompdf_strip_empty_resource_uris($html), 'UTF-8');
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

$bin = $dompdf->output();
if ($bin === '' || $bin === null) {
    fwrite(STDERR, "Falha ao gerar PDF.\n");
    exit(1);
}

if (file_put_contents($outFile, $bin) === false) {
    fwrite(STDERR, "Não foi possível gravar: {$outFile}\n");
    exit(1);
}

echo "OK: {$outFile} (" . strlen($bin) . " bytes)\n";
