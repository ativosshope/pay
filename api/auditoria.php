<?php
/**
 * Painel de Auditoria (somente leitura)
 * Acesso: /api/auditoria.php  — exige login de admin.
 *
 * Nada aqui altera dados: apenas SELECTs em transacoes, logs, sessions,
 * pix_initial_counter e upsell_templates.
 */
error_reporting(0);
ini_set('display_errors', '0');

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/database.php';

ensureSecureSession();

$PER_PAGE = 50;

// ───────────────────────────── Login / Logout ─────────────────────────────
$loginError = '';

if (isset($_GET['logout'])) {
    adminLogout();
    header('Location: auditoria.php');
    exit;
}

if (!isAdminAuthenticated() && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['aud_login'])) {
    $ip = getClientIp();
    if (isRateLimited($ip)) {
        $mins = (int)ceil(getRemainingBlockTime($ip) / 60);
        $loginError = "Muitas tentativas. Tente novamente em {$mins} minuto(s).";
    } else {
        $u = trim((string)($_POST['username'] ?? ''));
        $p = trim((string)($_POST['password'] ?? ''));
        if ($u === '' || $p === '') {
            $loginError = 'Usuário e senha são obrigatórios.';
        } elseif (verifyAdminPasswordSecure($p, $u)) {
            registerLoginAttempt($ip, true);
            setAdminAuthenticated(true);
            header('Location: auditoria.php');
            exit;
        } else {
            registerLoginAttempt($ip, false);
            $loginError = 'Usuário ou senha inválidos.';
        }
    }
}

function aud_head($title) {
    ?><!DOCTYPE html>
<html lang="pt-BR"><head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= htmlspecialchars($title) ?></title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{background:#0b1017;color:#dbe6f2;font:14px/1.45 ui-sans-serif,system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;padding:18px}
a{color:#38bdf8;text-decoration:none}
a:hover{text-decoration:underline}
.wrap{max-width:1400px;margin:0 auto}
h1{font-size:19px;margin-bottom:2px}
.sub{color:#64748b;font-size:12px;margin-bottom:14px}
.tabs{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px}
.tab{padding:7px 14px;border:1px solid #1e293b;border-radius:8px;background:#111a25;color:#93a4b8;font-weight:600;font-size:13px}
.tab.on{background:#0ea5e9;border-color:#0ea5e9;color:#04121d}
.card{background:#111a25;border:1px solid #1e293b;border-radius:12px;padding:14px;margin-bottom:14px}
form.filters{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:12px}
input,select{background:#0b1017;border:1px solid #24344a;color:#dbe6f2;padding:8px 10px;border-radius:8px;font-size:13px}
input[type=text]{min-width:250px}
button{background:#0ea5e9;border:0;color:#04121d;font-weight:700;padding:8px 14px;border-radius:8px;cursor:pointer;font-size:13px}
table{width:100%;border-collapse:collapse;font-size:12.5px}
th{text-align:left;color:#7b8ca3;font-weight:600;padding:8px;border-bottom:1px solid #1e293b;white-space:nowrap}
td{padding:8px;border-bottom:1px solid #16202d;vertical-align:top}
tr:hover td{background:#0f1824}
.mono{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:11.5px}
.pill{display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:700}
.p-paid{background:#052e1a;color:#4ade80;border:1px solid #14532d}
.p-pending{background:#2c2205;color:#fbbf24;border:1px solid #78350f}
.p-dead{background:#2b1114;color:#f87171;border:1px solid #7f1d1d}
.p-gray{background:#1b2430;color:#94a3b8;border:1px solid #2b3a4d}
.muted{color:#64748b}
.pager{display:flex;gap:6px;align-items:center;margin-top:12px;flex-wrap:wrap}
.pager a,.pager span{padding:6px 10px;border:1px solid #1e293b;border-radius:8px;font-size:12px}
.pager .cur{background:#0ea5e9;color:#04121d;border-color:#0ea5e9;font-weight:700}
pre{background:#080e15;border:1px solid #1b2836;border-radius:8px;padding:10px;overflow:auto;font-size:11.5px;color:#a5c4de;max-height:340px}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:10px}
.kv{background:#0d1521;border:1px solid #1b2836;border-radius:9px;padding:9px 11px}
.kv b{display:block;color:#64748b;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.4px;margin-bottom:2px}
.step{border-left:2px solid #1e293b;padding:0 0 14px 14px;position:relative}
.step:before{content:'';position:absolute;left:-6px;top:4px;width:10px;height:10px;border-radius:50%;background:#334155}
.step.paid:before{background:#22c55e}
.step.pending:before{background:#f59e0b}
.step.dead:before{background:#ef4444}
.login{max-width:340px;margin:12vh auto}
.err{background:#2b1114;border:1px solid #7f1d1d;color:#fca5a5;padding:9px;border-radius:8px;margin-bottom:10px;font-size:13px}
details summary{cursor:pointer;color:#38bdf8;font-size:12px}
</style></head><body><div class="wrap"><?php
}

if (!isAdminAuthenticated()) {
    aud_head('Auditoria — Login');
    ?>
    <div class="login card">
      <h1>Auditoria</h1>
      <p class="sub">Acesso restrito</p>
      <?php if ($loginError): ?><div class="err"><?= htmlspecialchars($loginError) ?></div><?php endif; ?>
      <form method="post">
        <input type="hidden" name="aud_login" value="1">
        <p style="margin-bottom:8px"><input type="text" name="username" placeholder="Usuário" autocomplete="off" style="width:100%"></p>
        <p style="margin-bottom:10px"><input type="password" name="password" placeholder="Senha" style="width:100%"></p>
        <button type="submit" style="width:100%">Entrar</button>
      </form>
    </div></div></body></html>
    <?php
    exit;
}

// ───────────────────────────── Helpers ─────────────────────────────
function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function brl($v) { return 'R$ ' . number_format((float)$v, 2, ',', '.'); }
function dt($v) { return $v ? date('d/m/Y H:i:s', strtotime($v)) : '—'; }

function q(array $extra = []) {
    $base = $_GET;
    foreach ($extra as $k => $v) {
        if ($v === null) unset($base[$k]); else $base[$k] = $v;
    }
    return 'auditoria.php?' . http_build_query($base);
}

/** Status efetivo: paid | pending | expired | failed */
function effStatus(array $t) {
    $s = strtolower((string)($t['status'] ?? ''));
    if ($s === 'paid' || $s === 'approved') return 'paid';
    if (in_array($s, ['failed', 'refused', 'canceled', 'cancelled', 'refunded', 'chargeback'], true)) return 'failed';
    if (!empty($t['expires_at']) && strtotime($t['expires_at']) < time()) return 'expired';
    return 'pending';
}

function statusPill($eff) {
    $map = [
        'paid' => ['PAGO', 'p-paid'],
        'pending' => ['PENDENTE', 'p-pending'],
        'expired' => ['EXPIRADO', 'p-gray'],
        'failed' => ['FALHOU', 'p-dead'],
    ];
    [$label, $cls] = $map[$eff] ?? ['?', 'p-gray'];
    return '<span class="pill ' . $cls . '">' . $label . '</span>';
}

/** Chave do lead: e-mail > telefone > ip > order_id */
function leadKey(array $t) {
    $e = strtolower(trim((string)($t['customer_email'] ?? '')));
    if ($e !== '' && $e !== 'cliente@email.com') return 'e:' . $e;
    $p = preg_replace('/\D/', '', (string)($t['customer_phone'] ?? ''));
    if ($p !== '' && $p !== '11999999999') return 'p:' . $p;
    $ip = trim((string)($t['customer_ip'] ?? ''));
    if ($ip !== '') return 'i:' . $ip;
    return 'o:' . ($t['order_id'] ?? '');
}

/** Mapa slug/título dos upsells para identificar a etapa do funil */
function upsellMap() {
    static $map = null;
    if ($map !== null) return $map;
    $map = [];
    try {
        foreach (Database::query("SELECT slug, title, sort_order FROM upsell_templates") as $u) {
            $map[mb_strtolower(trim($u['title']))] = $u;
            $map[mb_strtolower(trim($u['slug']))] = $u;
        }
    } catch (Exception $e) { /* tabela ausente */ }
    return $map;
}

/** Etapa: ['label' => ..., 'type' => 'main'|'upsell', 'order' => n] */
function stepOf(array $t) {
    $name = trim((string)($t['product_name'] ?? ''));
    $meta = json_decode((string)($t['metadata'] ?? ''), true);
    $slug = is_array($meta) ? (string)($meta['upsell'] ?? '') : '';
    $map = upsellMap();
    $keys = [];
    if ($slug !== '') $keys[] = mb_strtolower($slug);
    if ($name !== '') $keys[] = mb_strtolower($name);
    foreach ($keys as $k) {
        if (isset($map[$k])) {
            return ['label' => $map[$k]['title'], 'type' => 'upsell', 'order' => (int)$map[$k]['sort_order']];
        }
    }
    if ($slug !== '') return ['label' => $slug, 'type' => 'upsell', 'order' => 999];
    return ['label' => $name !== '' ? $name : 'Taxa de Cadastro', 'type' => 'main', 'order' => 0];
}

function originalOrderOf(array $t) {
    $meta = json_decode((string)($t['metadata'] ?? ''), true);
    return is_array($meta) ? (string)($meta['originalOrder'] ?? '') : '';
}

/** WHERE compartilhado pelos filtros de busca/status/período */
function buildFilters(&$params) {
    $where = [];
    $search = trim((string)($_GET['s'] ?? ''));
    $status = (string)($_GET['st'] ?? '');
    $range = (string)($_GET['r'] ?? 'all');

    if ($search !== '') {
        $where[] = "(order_id LIKE :s OR gateway_id LIKE :s OR customer_email LIKE :s OR customer_name LIKE :s OR customer_phone LIKE :s OR customer_ip LIKE :s OR product_name LIKE :s)";
        $params[':s'] = '%' . $search . '%';
    }
    if ($status === 'paid') {
        $where[] = "(status = 'paid' OR status = 'approved')";
    } elseif ($status === 'pending') {
        $where[] = "(status NOT IN ('paid','approved') AND (expires_at IS NULL OR expires_at >= NOW()))";
    } elseif ($status === 'expired') {
        $where[] = "(status NOT IN ('paid','approved') AND expires_at IS NOT NULL AND expires_at < NOW())";
    }
    $days = ['today' => 0, '7' => 7, '30' => 30];
    if ($range === 'today') {
        $where[] = "created_at >= CURDATE()";
    } elseif (isset($days[$range])) {
        $where[] = "created_at >= DATE_SUB(NOW(), INTERVAL " . (int)$days[$range] . " DAY)";
    }
    return $where ? (' WHERE ' . implode(' AND ', $where)) : '';
}

function filterBar($tab) {
    $s = h($_GET['s'] ?? '');
    $st = (string)($_GET['st'] ?? '');
    $r = (string)($_GET['r'] ?? 'all');
    $sel = function ($a, $b) { return $a === $b ? ' selected' : ''; };
    ?>
    <form class="filters" method="get">
      <input type="hidden" name="tab" value="<?= h($tab) ?>">
      <input type="text" name="s" value="<?= $s ?>" placeholder="order_id, e-mail, telefone, nome, IP, gateway_id...">
      <select name="st">
        <option value=""<?= $sel($st, '') ?>>Todos os status</option>
        <option value="paid"<?= $sel($st, 'paid') ?>>Pagos</option>
        <option value="pending"<?= $sel($st, 'pending') ?>>Pendentes</option>
        <option value="expired"<?= $sel($st, 'expired') ?>>Expirados</option>
      </select>
      <select name="r">
        <option value="all"<?= $sel($r, 'all') ?>>Todo o período</option>
        <option value="today"<?= $sel($r, 'today') ?>>Hoje</option>
        <option value="7"<?= $sel($r, '7') ?>>7 dias</option>
        <option value="30"<?= $sel($r, '30') ?>>30 dias</option>
      </select>
      <?php if ($tab === 'leads'): ?>
        <label class="muted" style="font-size:12.5px">
          <input type="checkbox" name="paidonly" value="1" <?= !empty($_GET['paidonly']) ? 'checked' : '' ?>>
          só quem pagou o principal
        </label>
      <?php endif; ?>
      <button type="submit">Filtrar</button>
      <a href="auditoria.php?tab=<?= h($tab) ?>" class="muted">limpar</a>
    </form>
    <?php
}

function pager($page, $total, $perPage) {
    $pages = max(1, (int)ceil($total / $perPage));
    if ($pages <= 1) { echo '<div class="pager"><span class="muted">' . $total . ' registro(s)</span></div>'; return; }
    echo '<div class="pager"><span class="muted">' . $total . ' registro(s) — página ' . $page . ' de ' . $pages . '</span>';
    $start = max(1, $page - 3);
    $end = min($pages, $start + 6);
    if ($page > 1) echo '<a href="' . h(q(['p' => $page - 1])) . '">‹ anterior</a>';
    for ($i = $start; $i <= $end; $i++) {
        echo $i === $page ? '<span class="cur">' . $i . '</span>' : '<a href="' . h(q(['p' => $i])) . '">' . $i . '</a>';
    }
    if ($page < $pages) echo '<a href="' . h(q(['p' => $page + 1])) . '">próxima ›</a>';
    echo '</div>';
}

$tab = (string)($_GET['tab'] ?? 'leads');
$page = max(1, (int)($_GET['p'] ?? 1));

aud_head('Auditoria');
?>
<h1>Auditoria do funil</h1>
<p class="sub">Somente leitura · <?= date('d/m/Y H:i') ?> · <a href="auditoria.php?logout=1">sair</a></p>
<div class="tabs">
  <a class="tab <?= $tab === 'leads' ? 'on' : '' ?>" href="auditoria.php?tab=leads">Leads</a>
  <a class="tab <?= $tab === 'compras' ? 'on' : '' ?>" href="auditoria.php?tab=compras">Todas as compras</a>
  <a class="tab <?= $tab === 'logs' ? 'on' : '' ?>" href="auditoria.php?tab=logs">Logs</a>
</div>
<?php

// ───────────────────────────── Detalhe do lead ─────────────────────────────
$detailOrder = trim((string)($_GET['order'] ?? ''));
$detailEmail = trim((string)($_GET['email'] ?? ''));
$detailIp = trim((string)($_GET['ip'] ?? ''));
$detailPhone = trim((string)($_GET['phone'] ?? ''));

if ($detailOrder || $detailEmail || $detailIp || $detailPhone) {
    $anchor = null;
    if ($detailOrder) {
        $anchor = Database::queryOne("SELECT * FROM transacoes WHERE order_id = :o", [':o' => $detailOrder]);
    }
    $conds = [];
    $params = [];
    if ($anchor) {
        if (!empty($anchor['customer_email']) && $anchor['customer_email'] !== 'cliente@email.com') {
            $conds[] = 'customer_email = :e'; $params[':e'] = $anchor['customer_email'];
        }
        if (!empty($anchor['customer_phone']) && $anchor['customer_phone'] !== '11999999999') {
            $conds[] = 'customer_phone = :ph'; $params[':ph'] = $anchor['customer_phone'];
        }
        if (!empty($anchor['customer_ip'])) { $conds[] = 'customer_ip = :ip'; $params[':ip'] = $anchor['customer_ip']; }
        $conds[] = 'order_id = :o'; $params[':o'] = $anchor['order_id'];
        $conds[] = "JSON_UNQUOTE(JSON_EXTRACT(metadata,'$.originalOrder')) = :o2"; $params[':o2'] = $anchor['order_id'];
    } else {
        if ($detailEmail) { $conds[] = 'customer_email = :e'; $params[':e'] = $detailEmail; }
        if ($detailIp) { $conds[] = 'customer_ip = :ip'; $params[':ip'] = $detailIp; }
        if ($detailPhone) { $conds[] = 'customer_phone = :ph'; $params[':ph'] = $detailPhone; }
        if ($detailOrder) { $conds[] = 'order_id = :o'; $params[':o'] = $detailOrder; }
    }

    $txs = [];
    if ($conds) {
        try {
            $txs = Database::query("SELECT * FROM transacoes WHERE " . implode(' OR ', $conds) . " ORDER BY created_at ASC", $params);
        } catch (Exception $e) {
            // instalações sem suporte a JSON_EXTRACT
            $conds = array_values(array_filter($conds, fn($c) => strpos($c, 'JSON_') === false));
            unset($params[':o2']);
            $txs = Database::query("SELECT * FROM transacoes WHERE " . implode(' OR ', $conds) . " ORDER BY created_at ASC", $params);
        }
    }

    if (!$txs) {
        echo '<div class="card">Nenhuma transação encontrada para esse filtro. <a href="auditoria.php">voltar</a></div></div></body></html>';
        exit;
    }

    $first = $txs[0];
    $paidTotal = 0; $genTotal = 0; $paidCount = 0;
    foreach ($txs as $t) {
        $genTotal += (float)$t['valor'];
        if (effStatus($t) === 'paid') { $paidTotal += (float)$t['valor']; $paidCount++; }
    }

    $sess = null;
    if (!empty($first['customer_ip'])) {
        $sess = Database::queryOne("SELECT * FROM sessions WHERE ip = :ip ORDER BY created_at DESC LIMIT 1", [':ip' => $first['customer_ip']]);
    }
    ?>
    <p style="margin-bottom:10px"><a href="auditoria.php?tab=leads">‹ voltar para a lista</a></p>
    <div class="card">
      <h1 style="margin-bottom:10px"><?= h($first['customer_name'] ?: 'Lead sem nome') ?></h1>
      <div class="grid">
        <div class="kv"><b>E-mail</b><?= h($first['customer_email'] ?: '—') ?></div>
        <div class="kv"><b>Telefone</b><?= h($first['customer_phone'] ?: '—') ?></div>
        <div class="kv"><b>Documento</b><?= h($first['customer_document'] ?: '—') ?></div>
        <div class="kv"><b>IP</b><?= h($first['customer_ip'] ?: '—') ?></div>
        <div class="kv"><b>Local</b><?= $sess ? h(trim(($sess['city'] ?? '') . ' / ' . ($sess['region'] ?? '') . ' ' . ($sess['country'] ?? ''))) : '—' ?></div>
        <div class="kv"><b>Primeiro contato</b><?= dt($first['created_at']) ?></div>
        <div class="kv"><b>PIX gerados</b><?= count($txs) ?> · <?= brl($genTotal) ?></div>
        <div class="kv"><b>Pagos</b><?= $paidCount ?> · <?= brl($paidTotal) ?></div>
      </div>
      <?php if ($sess && !empty($sess['user_agent'])): ?>
        <p class="muted mono" style="margin-top:10px"><?= h($sess['user_agent']) ?></p>
      <?php endif; ?>
    </div>

    <div class="card">
      <h1 style="font-size:16px;margin-bottom:12px">Linha do tempo do funil</h1>
      <?php
      $lastPaidLabel = null; $stoppedAt = null;
      foreach ($txs as $t):
          $eff = effStatus($t);
          $step = stepOf($t);
          if ($eff === 'paid') $lastPaidLabel = $step['label'];
          elseif ($stoppedAt === null) $stoppedAt = ['label' => $step['label'], 'at' => $t['created_at'], 'eff' => $eff];
          $cls = $eff === 'paid' ? 'paid' : ($eff === 'pending' ? 'pending' : 'dead');
      ?>
        <div class="step <?= $cls ?>">
          <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
            <strong><?= h($step['label']) ?></strong>
            <span class="pill p-gray"><?= $step['type'] === 'main' ? 'PRINCIPAL' : 'UPSELL' ?></span>
            <?= statusPill($eff) ?>
            <span><?= brl($t['valor']) ?></span>
            <span class="muted mono"><?= h($t['order_id']) ?></span>
          </div>
          <div class="muted" style="font-size:12px;margin-top:3px">
            gateway: <?= h($t['gateway']) ?> (<?= h($t['gateway_source'] ?: 'local') ?>)
            · gateway_id: <span class="mono"><?= h($t['gateway_id'] ?: '—') ?></span>
            · status bruto: <span class="mono"><?= h($t['status']) ?>/<?= h($t['gateway_status'] ?: '—') ?></span>
          </div>
          <div class="muted" style="font-size:12px">
            criado <?= dt($t['created_at']) ?> · expira <?= dt($t['expires_at']) ?> · pago <?= dt($t['paid_at']) ?>
            <?php $oo = originalOrderOf($t); if ($oo): ?> · pedido origem: <span class="mono"><?= h($oo) ?></span><?php endif; ?>
          </div>
          <details style="margin-top:6px">
            <summary>PIX bruto / metadata</summary>
            <pre><?= h($t['pix_code'] ?: '(sem código)') ?>

metadata: <?= h($t['metadata'] ?: '{}') ?>

utm: <?= h($t['utm_params'] ?: '{}') ?></pre>
          </details>
        </div>
      <?php endforeach; ?>
      <p style="margin-top:6px">
        <?php if ($lastPaidLabel): ?>
          Último pagamento confirmado: <strong><?= h($lastPaidLabel) ?></strong>.
        <?php else: ?>
          <span class="muted">Nenhum pagamento confirmado para este lead.</span>
        <?php endif; ?>
        <?php if ($stoppedAt): ?>
          Parou em <strong><?= h($stoppedAt['label']) ?></strong> — PIX <?= h($stoppedAt['eff']) ?> gerado em <?= dt($stoppedAt['at']) ?>.
        <?php endif; ?>
      </p>
    </div>

    <?php
    // Logs relacionados a qualquer order_id do lead
    $orderIds = array_map(fn($t) => $t['order_id'], $txs);
    $like = [];
    $lp = [];
    foreach ($orderIds as $i => $oid) { $like[] = "data LIKE :l$i"; $lp[":l$i"] = '%' . $oid . '%'; }
    $logs = [];
    try {
        $logs = Database::query("SELECT * FROM logs WHERE " . implode(' OR ', $like) . " ORDER BY created_at ASC LIMIT 500", $lp);
    } catch (Exception $e) {}
    ?>
    <div class="card">
      <h1 style="font-size:16px;margin-bottom:10px">Logs deste lead (<?= count($logs) ?>)</h1>
      <?php if (!$logs): ?><p class="muted">Nenhum log encontrado para os pedidos deste lead.</p><?php endif; ?>
      <?php foreach ($logs as $l): ?>
        <details style="margin-bottom:6px">
          <summary><span class="mono"><?= dt($l['created_at']) ?></span> — <strong><?= h($l['source']) ?></strong></summary>
          <pre><?= h(json_encode(json_decode((string)$l['data'], true), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>
        </details>
      <?php endforeach; ?>
    </div>

    <div class="card">
      <h1 style="font-size:16px;margin-bottom:10px">Reconsultar status no gateway</h1>
      <p class="muted" style="margin-bottom:8px">Consulta o gateway em tempo real (útil quando o webhook falhou).</p>
      <div style="display:flex;gap:8px;flex-wrap:wrap">
        <?php foreach ($txs as $t): ?>
          <button type="button" onclick="recheck('<?= h($t['order_id']) ?>')"><?= h($t['order_id']) ?></button>
        <?php endforeach; ?>
      </div>
      <pre id="recheck-out" style="margin-top:10px">—</pre>
    </div>
    <script>
    function recheck(id){
      var out=document.getElementById('recheck-out');
      out.textContent='consultando '+id+'...';
      fetch('verificar_status.php?order_id='+encodeURIComponent(id), {headers:{Accept:'application/json'}})
        .then(function(r){
          return r.text().then(function(body){
            var data;
            try { data=JSON.parse(body); }
            catch (_) { throw new Error('O servidor não retornou JSON válido (HTTP '+r.status+').'); }
            if (!r.ok || data.success === false) throw new Error(data.error || ('Falha HTTP '+r.status));
            return data;
          });
        })
        .then(function(j){out.textContent=JSON.stringify(j,null,2)})
        .catch(function(e){out.textContent='erro: '+e.message});
    }
    </script>
    </div></body></html>
    <?php
    exit;
}

// ───────────────────────────── Aba: Leads ─────────────────────────────
if ($tab === 'leads') {
    filterBar('leads');
    $params = [];
    $where = buildFilters($params);
    $rows = Database::query("SELECT * FROM transacoes{$where} ORDER BY created_at ASC", $params);

    $leads = [];
    foreach ($rows as $t) {
        $k = leadKey($t);
        if (!isset($leads[$k])) {
            $leads[$k] = [
                'name' => $t['customer_name'], 'email' => $t['customer_email'],
                'phone' => $t['customer_phone'], 'ip' => $t['customer_ip'],
                'first' => $t['created_at'], 'last' => $t['created_at'],
                'main' => null, 'mainOrder' => '', 'ups' => 0, 'upsPaid' => 0,
                'lastPaid' => '', 'paidTotal' => 0, 'genTotal' => 0, 'anyOrder' => $t['order_id'],
            ];
        }
        $L = &$leads[$k];
        $eff = effStatus($t);
        $step = stepOf($t);
        $L['last'] = $t['created_at'];
        $L['genTotal'] += (float)$t['valor'];
        if ($eff === 'paid') { $L['paidTotal'] += (float)$t['valor']; $L['lastPaid'] = $step['label']; }
        if ($step['type'] === 'main') {
            if ($L['main'] === null || $eff === 'paid') { $L['main'] = $eff; $L['mainOrder'] = $t['order_id']; }
        } else {
            $L['ups']++;
            if ($eff === 'paid') $L['upsPaid']++;
        }
        if ($L['name'] === '' || $L['name'] === null) $L['name'] = $t['customer_name'];
        unset($L);
    }

    if (!empty($_GET['paidonly'])) {
        $leads = array_filter($leads, fn($l) => $l['main'] === 'paid');
    }
    uasort($leads, fn($a, $b) => strtotime($b['last']) <=> strtotime($a['last']));

    $total = count($leads);
    $slice = array_slice($leads, ($page - 1) * $PER_PAGE, $PER_PAGE, true);
    ?>
    <div class="card">
      <table>
        <tr>
          <th></th><th>Lead</th><th>Contato</th><th>IP</th>
          <th>Ticket principal</th><th>Upsells</th><th>Último pago</th>
          <th>Pago</th><th>Gerado</th><th>Última ação</th><th></th>
        </tr>
        <?php foreach ($slice as $l):
          $dot = $l['main'] === 'paid' ? '#22c55e' : (($l['genTotal'] > 0) ? '#f59e0b' : '#64748b');
          $link = q(['tab' => null, 'p' => null, 'order' => $l['mainOrder'] ?: $l['anyOrder']]);
        ?>
        <tr>
          <td><span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:<?= $dot ?>"></span></td>
          <td><strong><?= h($l['name'] ?: '—') ?></strong></td>
          <td class="mono"><?= h($l['email'] ?: '—') ?><br><span class="muted"><?= h($l['phone'] ?: '—') ?></span></td>
          <td class="mono"><?= h($l['ip'] ?: '—') ?></td>
          <td><?= $l['main'] ? statusPill($l['main']) : '<span class="muted">—</span>' ?></td>
          <td><?= (int)$l['upsPaid'] ?>/<?= (int)$l['ups'] ?></td>
          <td><?= h($l['lastPaid'] ?: '—') ?></td>
          <td><strong><?= brl($l['paidTotal']) ?></strong></td>
          <td class="muted"><?= brl($l['genTotal']) ?></td>
          <td class="muted"><?= dt($l['last']) ?></td>
          <td><a href="<?= h($link) ?>">detalhes ›</a></td>
        </tr>
        <?php endforeach; ?>
      </table>
      <?php pager($page, $total, $PER_PAGE); ?>
    </div>
    <?php
}

// ───────────────────────────── Aba: Todas as compras ─────────────────────────────
if ($tab === 'compras') {
    filterBar('compras');
    $params = [];
    $where = buildFilters($params);
    $totalRow = Database::queryOne("SELECT COUNT(*) AS c FROM transacoes{$where}", $params);
    $total = (int)($totalRow['c'] ?? 0);
    $offset = ($page - 1) * $PER_PAGE;
    $rows = Database::query(
        "SELECT * FROM transacoes{$where} ORDER BY created_at DESC LIMIT {$PER_PAGE} OFFSET {$offset}",
        $params
    );
    ?>
    <div class="card">
      <table>
        <tr>
          <th>Data</th><th>Order ID</th><th>Lead</th><th>Etapa</th>
          <th>Valor</th><th>Gateway</th><th>Status</th><th>Pago em</th><th></th>
        </tr>
        <?php foreach ($rows as $t):
          $eff = effStatus($t); $step = stepOf($t);
        ?>
        <tr>
          <td class="muted"><?= dt($t['created_at']) ?></td>
          <td class="mono"><?= h($t['order_id']) ?></td>
          <td><?= h($t['customer_name'] ?: '—') ?><br><span class="muted mono"><?= h($t['customer_email'] ?: $t['customer_ip']) ?></span></td>
          <td><?= h($step['label']) ?> <span class="pill p-gray"><?= $step['type'] === 'main' ? 'PRINCIPAL' : 'UPSELL' ?></span></td>
          <td><?= brl($t['valor']) ?></td>
          <td class="muted"><?= h($t['gateway']) ?><br><span class="mono"><?= h($t['gateway_source'] ?: 'local') ?></span></td>
          <td><?= statusPill($eff) ?><br><span class="muted mono"><?= h($t['status']) ?></span></td>
          <td class="muted"><?= dt($t['paid_at']) ?></td>
          <td><a href="<?= h(q(['tab' => null, 'p' => null, 'order' => $t['order_id']])) ?>">ver lead ›</a></td>
        </tr>
        <?php endforeach; ?>
      </table>
      <?php pager($page, $total, $PER_PAGE); ?>
    </div>
    <?php
}

// ───────────────────────────── Aba: Logs ─────────────────────────────
if ($tab === 'logs') {
    $src = trim((string)($_GET['src'] ?? ''));
    $txt = trim((string)($_GET['s'] ?? ''));
    $sources = [];
    try { $sources = Database::query("SELECT DISTINCT source FROM logs ORDER BY source ASC"); } catch (Exception $e) {}
    ?>
    <form class="filters" method="get">
      <input type="hidden" name="tab" value="logs">
      <input type="text" name="s" value="<?= h($txt) ?>" placeholder="buscar no conteúdo do log (order_id, erro...)">
      <select name="src">
        <option value="">Todas as origens</option>
        <?php foreach ($sources as $s): ?>
          <option value="<?= h($s['source']) ?>"<?= $src === $s['source'] ? ' selected' : '' ?>><?= h($s['source']) ?></option>
        <?php endforeach; ?>
      </select>
      <button type="submit">Filtrar</button>
      <a href="auditoria.php?tab=logs" class="muted">limpar</a>
    </form>
    <?php
    $w = []; $p = [];
    if ($src !== '') { $w[] = 'source = :src'; $p[':src'] = $src; }
    if ($txt !== '') { $w[] = '(data LIKE :t OR source LIKE :t)'; $p[':t'] = '%' . $txt . '%'; }
    $where = $w ? (' WHERE ' . implode(' AND ', $w)) : '';
    $totalRow = Database::queryOne("SELECT COUNT(*) AS c FROM logs{$where}", $p);
    $total = (int)($totalRow['c'] ?? 0);
    $offset = ($page - 1) * $PER_PAGE;
    $logs = Database::query("SELECT * FROM logs{$where} ORDER BY created_at DESC LIMIT {$PER_PAGE} OFFSET {$offset}", $p);
    ?>
    <div class="card">
      <?php foreach ($logs as $l): ?>
        <details style="margin-bottom:6px">
          <summary><span class="mono"><?= dt($l['created_at']) ?></span> — <strong><?= h($l['source']) ?></strong></summary>
          <pre><?= h(json_encode(json_decode((string)$l['data'], true), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>
        </details>
      <?php endforeach; ?>
      <?php if (!$logs): ?><p class="muted">Nenhum log encontrado.</p><?php endif; ?>
      <?php pager($page, $total, $PER_PAGE); ?>
    </div>
    <?php
}
?>
</div></body></html>
