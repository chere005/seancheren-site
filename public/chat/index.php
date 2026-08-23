<?php
// A page served under /test/ (the sandbox mirror) loads lib-test/ instead of lib/,
// isolated in code, config and data. Links stay root-relative — the sandbox is a
// subdomain and .htaccess maps test.seancheren.com/X to /test/X — so nothing here
// prefixes a href. Keep this preamble identical when adding a page.
//
// THREE signals, and all three are needed. __DIR__ with a bare strpos for '/test/'
// missed the instance's OWN top-level page — /home/public/test/index.php sits in
// /home/public/test, with no trailing slash — so the sandbox home silently loaded
// production's lib AND production's data. The host check is what the subdomain
// routing needs: test.seancheren.com/X is rewritten to /test/X internally, so
// REQUEST_URI never says /test/ there either.
$__host   = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
// Which sandbox, if any — '' is production. dev came back on 2026-08-23
// ("deploy a clone from prod to test and dev"), same three signals as test.
$__inst   = '';
foreach (['test', 'dev'] as $__i) {
    if (preg_match('#/' . $__i . '(/|$)#', __DIR__) === 1
        || strncmp($_SERVER['REQUEST_URI'] ?? '', '/' . $__i . '/', strlen($__i) + 2) === 0
        || strncmp($__host, $__i . '.', strlen($__i) + 1) === 0) { $__inst = $__i; break; }
}
$__libDir = null;
$__cands  = $__inst !== ''
    ? [__DIR__ . '/../../../lib-' . $__inst, '/home/protected/lib-' . $__inst]
    : [__DIR__ . '/../../lib',      '/home/protected/lib'];
foreach ($__cands as $__c) {
    if (is_file($__c . '/auth.php')) { $__libDir = $__c; break; }
}
require_once $__libDir . '/auth.php';   // for app_config() only — NO require_login here (public/anonymous)

$cfg      = app_config();
$dataFile = rtrim($cfg['data_dir'], '/') . '/chat.json';   // private, never web-served

const CHAT_MAX_MESSAGES = 500;   // keep the last N in the record
const CHAT_MAX_TEXT     = 1000;
const CHAT_MAX_NAME      = 40;

function load_chat(string $file): array { return store_read($file); }

function save_chat(string $file, array $msgs): void
{
    if (count($msgs) > CHAT_MAX_MESSAGES) {
        $msgs = array_slice($msgs, -CHAT_MAX_MESSAGES);
    }
    store_write($file, array_values($msgs));
}

function e(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES);
}

$isAjax = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';

// --- Live feed endpoint: GET ?feed=1 -> JSON of messages ---
if (isset($_GET['feed'])) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(load_chat($dataFile), JSON_UNESCAPED_SLASHES);
    exit;
}

// --- Post a message ---
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'send') {
    $name = trim((string) ($_POST['name'] ?? ''));
    $text = trim((string) ($_POST['text'] ?? ''));
    $name = $name === '' ? 'anonymous' : mb_substr($name, 0, CHAT_MAX_NAME);

    if ($text !== '') {
        $msgs   = load_chat($dataFile);
        $msgs[] = [
            'name' => $name,
            'text' => mb_substr($text, 0, CHAT_MAX_TEXT),
            'ts'   => time(),
        ];
        save_chat($dataFile, $msgs);
    }

    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true, 'messages' => load_chat($dataFile)], JSON_UNESCAPED_SLASHES);
        exit;
    }
    header('Location: ' . _self_path());   // PRG for the no-JS path
    exit;
}

$messages = load_chat($dataFile);
?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <title>Chat</title>
  <meta name="theme-color" content="#111111">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="black">
  <meta name="apple-mobile-web-app-title" content="Chat">
  <link rel="apple-touch-icon" href="<?= suite_base() ?>/chat/icon-180.png">
  <link rel="icon" href="<?= suite_base() ?>/chat/icon-192.png">
  <link rel="manifest" href="<?= suite_base() ?>/chat/manifest.webmanifest">
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    html, body { height: 100%; }
    body {
      font-family: system-ui, sans-serif; background: #111; color: #eee;
      display: flex; flex-direction: column; height: 100dvh;
    }
    .topbar {
      display: flex; align-items: center; gap: 0.6rem; padding: 0.7rem 1rem;
      border-bottom: 1px solid #242424; background: #161616;
    }
    .topbar label { font-size: 0.8rem; color: #888; white-space: nowrap; }
    .topbar input {
      flex: 1; max-width: 260px; padding: 0.4rem 0.6rem; background: #222;
      border: 1px solid #3a3a3a; border-radius: 6px; color: #eee; font-size: 0.95rem;
    }
    .topbar input:focus { outline: none; border-color: #888; }
    .topbar .hint { margin-left: auto; font-size: 0.7rem; color: #555; }

    #log {
      flex: 1; overflow-y: auto; padding: 1rem; display: flex; flex-direction: column; gap: 0.5rem;
    }
    .msg { max-width: 85%; }
    .msg .who { font-size: 0.72rem; color: var(--accent); margin-bottom: 1px; }
    .msg .body {
      display: inline-block; background: #1c1c1c; border: 1px solid #262626;
      border-radius: 8px; padding: 0.45rem 0.65rem; font-size: 0.95rem;
      white-space: pre-wrap; word-break: break-word;
    }
    .msg .time { font-size: 0.62rem; color: #555; margin-left: 0.4rem; }
    .empty { color: #555; text-align: center; margin: auto; font-size: 0.9rem; }

    form.send {
      display: flex; gap: 0.5rem; padding: 0.7rem 1rem;
      padding-bottom: calc(0.7rem + env(safe-area-inset-bottom, 0px));
      border-top: 1px solid #242424; background: #161616;
    }
    form.send input {
      flex: 1; padding: 0.6rem 0.8rem; background: #222; border: 1px solid #3a3a3a;
      border-radius: 8px; color: #eee; font-size: 1rem;
    }
    form.send input:focus { outline: none; border-color: #888; }
    form.send button {
      padding: 0.6rem 1.1rem; background: var(--accent); color: var(--accent-ink); border: none;
      border-radius: 8px; font-size: 1rem; font-weight: 700; cursor: pointer;
    }
    form.send button:hover { background: #52e0ac; }
  </style>
</head>
<body>
  <div class="topbar">
    <label for="name">Username</label>
    <input id="name" type="text" placeholder="anonymous" maxlength="<?= CHAT_MAX_NAME ?>" autocomplete="off">
    <span class="hint">no login &middot; anyone can chat</span>
  </div>

  <div id="log">
    <?php if (!$messages): ?>
      <p class="empty" id="empty">No messages yet. Say something.</p>
    <?php else: ?>
      <?php foreach ($messages as $m): ?>
        <div class="msg">
          <div class="who"><?= e($m['name'] ?? 'anonymous') ?><span class="time"><?= e(date('M j, g:ia', (int) ($m['ts'] ?? 0))) ?></span></div>
          <div class="body"><?= e($m['text'] ?? '') ?></div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <form class="send" method="post" action="" autocomplete="off">
    <input type="hidden" name="action" value="send">
    <input type="hidden" name="name" id="nameHidden" value="">
    <input type="text" name="text" id="text" placeholder="Type a message…" maxlength="<?= CHAT_MAX_TEXT ?>" required autofocus>
    <button type="submit">Send</button>
  </form>

  <script>
    const nameInput  = document.getElementById('name');
    const nameHidden = document.getElementById('nameHidden');
    const textInput  = document.getElementById('text');
    const form       = document.querySelector('form.send');
    const log        = document.getElementById('log');

    // Remember the username locally between visits (convenience only).
    nameInput.value = localStorage.getItem('chatName') || '';
    const syncName = () => {
      nameHidden.value = nameInput.value;                 // whatever is typed NOW is the sender
      localStorage.setItem('chatName', nameInput.value);
    };
    nameInput.addEventListener('input', syncName);
    syncName();

    const fmtTime = ts => {
      const d = new Date(ts * 1000);
      return d.toLocaleString([], { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
    };

    const render = msgs => {
      const atBottom = log.scrollHeight - log.scrollTop - log.clientHeight < 40;
      log.innerHTML = '';
      if (!msgs.length) {
        const p = document.createElement('p');
        p.className = 'empty'; p.textContent = 'No messages yet. Say something.';
        log.appendChild(p);
      }
      for (const m of msgs) {
        const wrap = document.createElement('div'); wrap.className = 'msg';
        const who  = document.createElement('div'); who.className = 'who';
        who.textContent = m.name || 'anonymous';
        const time = document.createElement('span'); time.className = 'time';
        time.textContent = fmtTime(m.ts || 0); who.appendChild(time);
        const body = document.createElement('div'); body.className = 'body';
        body.textContent = m.text || '';                  // textContent = no XSS
        wrap.appendChild(who); wrap.appendChild(body); log.appendChild(wrap);
      }
      if (atBottom) log.scrollTop = log.scrollHeight;
    };

    const poll = () =>
      fetch('?feed=1', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(r => r.json()).then(render).catch(() => {});

    form.addEventListener('submit', e => {
      e.preventDefault();
      syncName();
      const text = textInput.value.trim();
      if (!text) return;
      const data = new URLSearchParams({ action: 'send', name: nameHidden.value, text });
      fetch('', { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: data })
        .then(r => r.json()).then(res => { textInput.value = ''; render(res.messages || []); textInput.focus(); })
        .catch(() => {});
    });

    // Live updates.
    log.scrollTop = log.scrollHeight;
    setInterval(poll, 3000);
  </script>
</body>
</html>
