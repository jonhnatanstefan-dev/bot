<?php
declare(strict_types=1);
/* ISP-IA-PANEL: painel de laboratório, sem WhatsApp. PHP 8+.
 * A raiz HTTP DEVE apontar para /public, NUNCA para a pasta do projeto. */
ini_set('session.use_strict_mode', '1');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'self'; script-src 'unsafe-inline'; style-src 'unsafe-inline'; object-src 'none'; base-uri 'none'; frame-ancestors 'none'");
header('Cache-Control: no-store, private');
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || ((string)($_SERVER['SERVER_PORT'] ?? '') === '443');
if (!$isHttps) {
    http_response_code(403);
    exit('Acesso permitido somente por HTTPS. Ative SSL no aaPanel.');
}
session_name('isp_ai_lab');
session_set_cookie_params([
    'lifetime'=>0, 'path'=>'/', 'secure'=>true,
    'httponly'=>true, 'samesite'=>'Strict'
]);
session_start();
$root = dirname(__DIR__);
$file = $root . '/private/config.php';
if (!is_readable($file)) {
    http_response_code(503);
    exit('Painel não configurado. Execute o instalador do aaPanel.');
}
$config = require $file;
if (!is_array($config) || !isset($config['password_hash'], $config['backend_token'])) {
    http_response_code(503);
    exit('Configuração do painel inválida.');
}
if (!isset($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(24));
}
function csrf_ok(): bool {
    return is_string($_POST['csrf'] ?? null)
        && hash_equals((string)$_SESSION['csrf'], (string)$_POST['csrf']);
}
function json_out(int $status, array $body): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
function rate_limited(string $root, string $ip, bool $failed): bool {
    $dir = $root . '/private/attempts';
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        return true;
    }
    $path = $dir . '/' . hash('sha256', $ip) . '.json';
    $fh = fopen($path, 'c+');
    if (!$fh) return true;
    try {
        if (!flock($fh, LOCK_EX)) return true;
        $content = stream_get_contents($fh);
        $data = json_decode($content ?: '[]', true);
        $now = time();
        $attempts = array_values(array_filter(
            is_array($data) ? $data : [],
            static fn($t) => is_int($t) && $t > $now-900
        ));
        $blocked = count($attempts) >= 5;
        if ($failed && !$blocked) $attempts[] = $now;
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, json_encode($attempts));
        fflush($fh);
        flock($fh, LOCK_UN);
        return $blocked;
    } finally { fclose($fh); }
}
$action = (string)($_POST['action'] ?? '');
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'login') {
    if (!csrf_ok()) { http_response_code(403); exit('Sessão expirada. Recarregue.'); }
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    if (rate_limited($root, $ip, false)) {
        $error = 'Muitas tentativas. Tente novamente em 15 minutos.';
    } else {
        $user = (string)($_POST['username'] ?? '');
        $pass = (string)($_POST['password'] ?? '');
        $ok = hash_equals((string)($config['username'] ?? 'admin'), $user)
            && password_verify($pass, (string)$config['password_hash']);
        if ($ok) {
            session_regenerate_id(true);
            $_SESSION['auth'] = true;
            $_SESSION['csrf'] = bin2hex(random_bytes(24));
            header('Location: /', true, 303);
            exit;
        }
        rate_limited($root, $ip, true);
        $error = 'Usuário ou senha incorretos.';
    }
}
$authenticated = ($_SESSION['auth'] ?? false) === true;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'logout') {
    if (!csrf_ok()) { http_response_code(403); exit('CSRF inválido'); }
    $_SESSION = [];
    session_destroy();
    header('Location: /', true, 303);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($action, ['chat','history'], true)) {
    if (!$authenticated) json_out(401, ['error'=>'Faça login novamente']);
    if (!csrf_ok()) json_out(403, ['error'=>'CSRF inválido']);
    $company = $_POST['company'] ?? '';
    $session = $_POST['session'] ?? '';
    if (!is_string($company) || !in_array($company, ['NET+','SPPDNET'], true)) {
        json_out(422, ['error'=>'Empresa inválida']);
    }
    if (!is_string($session) || !preg_match('/^[A-Za-z0-9_.-]{1,64}$/D', $session)) {
        json_out(422, ['error'=>'Sessão inválida']);
    }
    $message = $_POST['message'] ?? '';
    if ($action === 'chat' &&
        (!is_string($message) || mb_strlen(trim($message), 'UTF-8') < 1 || mb_strlen($message, 'UTF-8') > 2000)) {
        json_out(422, ['error'=>'Mensagem inválida (1 a 2000 caracteres)']);
    }
    if (!extension_loaded('curl')) json_out(503, ['error'=>'Ative a extensão PHP cURL no aaPanel']);
    $url = 'http://127.0.0.1:18787' . ($action === 'chat' ? '/api/chat' :
           '/api/history?' . http_build_query(['company'=>$company, 'session'=>$session]));
    $curl = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 165,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer '.(string)$config['backend_token'],
                               'Accept: application/json'],
    ];
    if ($action === 'chat') {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = json_encode(
            ['company'=>$company,'session'=>$session,'message'=>$message], JSON_UNESCAPED_UNICODE
        );
        $opts[CURLOPT_HTTPHEADER][] = 'Content-Type: application/json';
    }
    curl_setopt_array($curl, $opts);
    $body = curl_exec($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $curlError = curl_errno($curl);
    curl_close($curl);
    if ($curlError !== 0 || !is_string($body)) {
        json_out(503, ['error'=>'Servidor de IA indisponível. Verifique o túnel SSH.']);
    }
    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        json_out(502, ['error'=>'Resposta inválida da IA']);
    }
    json_out($status >= 400 && $status <= 599 ? $status : 200, $decoded);
}
function esc(string $text): string {
    return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Central IA — NET+ / SPPDNET</title>
<style>
:root {font-family:system-ui,-apple-system,"Segoe UI",sans-serif;color-scheme:dark}
* {box-sizing:border-box}
body {margin:0;background:#0d1523;color:#eef4fb}
header {border-bottom:1px solid #2b3b51;background:#111e30;padding:17px 28px}
.shell {max-width:1000px;margin:26px auto;padding:0 18px}
h1 {font-size:23px;margin:0 0 5px} h2 {font-size:18px}
.muted,small {color:#a6b6ca}
.tag {border:1px solid #35506f;border-radius:18px;padding:5px 10px;font-size:12px;color:#bfdcf5}
.panel {background:#152239;border:1px solid #2e4058;border-radius:14px;padding:23px}
.login {max-width:430px;margin:70px auto}
label {display:block;font-weight:600;font-size:13px;margin:14px 0 6px}
input,textarea,select,button {font:inherit;border-radius:8px}
input,textarea,select {width:100%;background:#0f1b2d;color:#f3f7fd;border:1px solid #3a536f;padding:12px}
textarea {min-height:105px;resize:vertical}
button {cursor:pointer;background:#2385c6;border:none;color:white;padding:11px 17px;font-weight:600}
button:hover {background:#146ba8}button:disabled {opacity:.55;cursor:wait}
button.subtle {background:#263b56}
.toolbar {display:flex;gap:14px;align-items:center;justify-content:space-between;margin-bottom:18px;flex-wrap:wrap}
.cols {display:grid;grid-template-columns:1fr 1fr;gap:14px}
@media(max-width:650px){.cols{grid-template-columns:1fr}}
pre {background:#0d1a2a;white-space:pre-wrap;overflow-wrap:anywhere;padding:18px;border:1px solid #34465e;border-radius:12px;min-height:110px;line-height:1.55}
.buttons {display:flex;gap:10px;flex-wrap:wrap;margin-top:14px}
.error {color:#ffc9c9}
footer {margin-top:20px;font-size:13px;color:#a6b6ca}
</style>
</head>
<body>
<header><div class="toolbar" style="margin:0"><div><h1>Central de Inteligência Artificial</h1>
<small>NET+ / SPPDNET</small></div><span class="tag">LABORATÓRIO · SEM WHATSAPP</span></div></header>
<main class="shell">
<?php if (!$authenticated): ?>
<section class="panel login">
<h2>Entrar no painel</h2>
<p class="muted">Área administrativa de simulação. Não use informações reais de clientes.</p>
<?php if ($error): ?><p class="error"><?=esc($error)?></p><?php endif; ?>
<form method="post">
<input type="hidden" name="action" value="login">
<input type="hidden" name="csrf" value="<?=esc((string)$_SESSION['csrf'])?>">
<label for="username">Usuário</label><input id="username" name="username" value="admin" required autocomplete="username">
<label for="password">Senha</label><input id="password" type="password" name="password" autocomplete="current-password" required>
<div class="buttons"><button type="submit">Entrar</button></div>
</form></section>
<?php else: ?>
<div class="toolbar">
<div><h2>Simulador de atendimento</h2><p class="muted">Conectado ao modelo Qwen local por canal SSH. Histórico separado por empresa.</p></div>
<form method="post"><input type="hidden" name="action" value="logout">
<input type="hidden" name="csrf" value="<?=esc((string)$_SESSION['csrf'])?>">
<button type="submit" class="subtle">Sair</button></form>
</div>
<section class="panel">
<div class="cols">
<div><label for="company">Empresa</label><select id="company"><option value="NET+">NET+</option><option value="SPPDNET">SPPDNET</option></select></div>
<div><label for="session">Sessão fictícia</label><input id="session" maxlength="64" value="teste-001" pattern="[A-Za-z0-9_.-]+"></div>
</div>
<label for="msg">Mensagem fictícia do cliente</label>
<textarea id="msg" maxlength="2000">Olá! Minha internet está sem conexão desde ontem.</textarea>
<div class="buttons"><button id="send" type="button">Testar IA</button>
<button id="history" type="button" class="subtle">Ver histórico</button></div>
<h2>Resposta</h2><pre id="out" aria-live="polite">Nenhum teste realizado.</pre>
<small>As mensagens deste laboratório não são enviadas pelo WhatsApp.</small>
</section>
<script>
(function(){
 const csrf=<?=json_encode((string)$_SESSION['csrf'])?>;
 const el=id=>document.getElementById(id);
 async function send(action){
   const params=new URLSearchParams({
      action:action,csrf:csrf,company:el('company').value,session:el('session').value,
      message:el('msg').value
   });
   const response=await fetch('/',{
     method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:params,
     credentials:'same-origin',cache:'no-store'
   });
   const body=await response.json();
   if(!response.ok)throw Error(body.error||'Erro HTTP '+response.status);
   return body;
 }
 el('send').onclick=async function(){
   const button=this;button.disabled=true;el('out').textContent='Processando no servidor local...';
   try{
      const data=await send('chat');
      el('out').textContent=data.reply+'\n\nModelo: '+data.model+' | Tempo: '+data.elapsed_s+' s';
   }catch(e){el('out').textContent='Erro: '+e.message}
   finally{button.disabled=false}
 };
 el('history').onclick=async function(){
   const button=this;button.disabled=true;el('out').textContent='Carregando...';
   try{
      const data=await send('history');
      el('out').textContent=data.messages.map(x=>x.role+': '+x.content).join('\n\n')||'Sem histórico';
   }catch(e){el('out').textContent='Erro: '+e.message}
   finally{button.disabled=false}
 };
})();
</script>
<?php endif; ?>
<footer>v0.2 — Modo de laboratório. Operações técnicas, financeiras e integração com o WhatsApp desativadas.</footer>
</main></body></html>
