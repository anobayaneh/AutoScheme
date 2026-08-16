<?php
/**
 * ╔══════════════════════════════════════════════════════════════════════╗
 * ║  TELEGRAM → GITHUB → RENDER DEPLOY BOT   (v6 — multi-account, clean)  ║
 * ╚══════════════════════════════════════════════════════════════════════╝
 *
 *  HOW IT WORKS
 *  The original repo is treated as a TEMPLATE and is never modified.
 *  Every client gets their own copy:
 *
 *      AveaBeauty  (template — untouched)
 *          ├── copy → AveaBeauty-Zae   → own config.php → Render service #1
 *          └── copy → AveaBeauty-Mika  → own config.php → Render service #2
 *
 *  RENDER ACCOUNTS
 *  Add as many Render accounts as you want via the RENDER_ACCOUNTS
 *  environment variable — a JSON array, set once in Render's dashboard:
 *
 *    RENDER_ACCOUNTS = [
 *      {"name":"Main",   "key":"rnd_xxxxxxxxxxxxx"},
 *      {"name":"Backup", "key":"rnd_yyyyyyyyyyyyy"}
 *    ]
 *
 *  Only "key" is required — the bot looks up each account's owner ID and
 *  email from Render automatically. Add more accounts anytime by editing
 *  this one variable and saving (Render redeploys, then they show up in
 *  the bot immediately). No in-chat add/remove — this env var is the
 *  single source of truth, so what you see in Telegram always matches
 *  what's actually configured.
 *
 *  Everywhere you view or manage sites you first pick which account to
 *  look at (or "🌐 All accounts" to see everything at once). When
 *  deploying a new site, 🎲 Auto is the default — it rotates across your
 *  accounts, and the moment Render itself reports the current one is
 *  full it silently moves to the next.
 *
 *  If RENDER_ACCOUNTS isn't set, the bot falls back to the old single
 *  RENDER_API_KEY / RENDER_OWNER_ID env vars as "Account 1".
 *
 *  COMMANDS
 *    /start /menu → main menu           /status → latest deploy state
 *    /newhost     → deployment wizard   /logs   → recent log lines
 *    /sites       → manage live sites   /check  → test API connections
 *    /repos       → list bot-made repos /accounts → your Render accounts
 *    /cancel      → abort the wizard    /help   → how this works
 *
 *  All secrets live in Render environment variables. Nothing is hardcoded.
 */

date_default_timezone_set('Asia/Manila');
mb_internal_encoding('UTF-8');

// ============================================================
//  1. CONFIGURATION  (Render → your service → Environment)
// ============================================================
$TELEGRAM_TOKEN  = getenv('TELEGRAM_TOKEN');
$ADMIN_CHAT_ID   = getenv('ADMIN_CHAT_ID');
$GITHUB_TOKEN    = getenv('GITHUB_TOKEN');
$GITHUB_OWNER    = getenv('GITHUB_OWNER');
$RENDER_REGION   = getenv('RENDER_REGION') ?: 'singapore';
$CONFIG_PATH     = getenv('CONFIG_PATH') ?: 'config.php';
$STATE_FILE      = getenv('STATE_FILE') ?: '/tmp/bot_state.json';
$DEFAULT_PRIVATE = getenv('CLONE_PRIVATE') === null ? true : (getenv('CLONE_PRIVATE') !== 'no');

$PAGE_SIZE      = 8;     // buttons per page
$MAX_COPY_FILES = 300;   // ceiling for the fallback copy method

/**
 * Render accounts this bot can deploy to / manage — see the header
 * comment above for the RENDER_ACCOUNTS format. Missing owner_id values
 * are filled in automatically further down, once render_owners_for_key()
 * is available (PHP hoists top-level function declarations, so calling
 * it before its textual definition is fine).
 */
$RENDER_ACCOUNTS = [];
$rawAccounts = getenv('RENDER_ACCOUNTS');
if ($rawAccounts) {
    $parsed = json_decode($rawAccounts, true);
    if (is_array($parsed)) {
        foreach ($parsed as $i => $a) {
            $key = $a['key'] ?? $a['api_key'] ?? $a['RENDER_API_KEY'] ?? '';
            $oid = $a['owner_id'] ?? $a['ownerId'] ?? $a['RENDER_OWNER_ID'] ?? '';
            if ($key === '') continue;
            $RENDER_ACCOUNTS[] = [
                'name' => $a['name'] ?? ('Account ' . ($i + 1)),
                'key' => $key, 'owner_id' => $oid, 'email' => $a['email'] ?? '',
            ];
        }
    }
}
if (!$RENDER_ACCOUNTS) {
    $fk = getenv('RENDER_API_KEY');
    $fo = getenv('RENDER_OWNER_ID');
    if ($fk) $RENDER_ACCOUNTS[] = ['name' => 'Account 1', 'key' => $fk, 'owner_id' => $fo ?: '', 'email' => ''];
}
foreach ($RENDER_ACCOUNTS as $i => $a) {
    if (!empty($a['owner_id'])) continue;
    [, $owners] = render_owners_for_key($a['key']);
    if ($owners) {
        $RENDER_ACCOUNTS[$i]['owner_id'] = $owners[0]['id'];
        if (empty($RENDER_ACCOUNTS[$i]['email'])) $RENDER_ACCOUNTS[$i]['email'] = $owners[0]['email'] ?: $owners[0]['name'];
    }
}

/**
 * The questions the wizard asks, in order.
 *   label    – shown in bold
 *   emoji    – visual anchor
 *   hint     – where to get this value
 *   required – cannot be skipped
 *   secret   – masked in recaps; the bot tries to delete your message
 */
$CONFIG_FIELDS = [
    'telegram_bot_token' => [
        'label' => 'Telegram bot token',
        'emoji' => '🤖',
        'hint'  => 'From @BotFather. Looks like 1234567890:AAHx…',
        'required' => true,  'secret' => true,
    ],
    'telegram_chat_id' => [
        'label' => 'Telegram chat ID',
        'emoji' => '🆔',
        'hint'  => 'Numbers only. Groups start with a minus, e.g. -1001234567890',
        'required' => true,  'secret' => false,
    ],
    'discord_webhook_url' => [
        'label' => 'Discord webhook URL',
        'emoji' => '💬',
        'hint'  => 'Optional. Tap Skip to keep whatever the template already has.',
        'required' => false, 'secret' => true,
    ],
];

/** One answer can fill several variables in config.php. */
$FIELD_ALIASES = [
    'telegram_chat_id' => ['telegram_forward_chat_id'],
];

/** Render deploy status → emoji + plain-English meaning. */
$STATUS_INFO = [
    'created'                => ['🆕', 'Queued up, waiting for a build slot'],
    'queued'                 => ['🕒', 'Waiting in line to start'],
    'build_in_progress'      => ['🏗️', 'Building your Docker image'],
    'update_in_progress'     => ['🔄', 'Swapping in the new version'],
    'pre_deploy_in_progress' => ['🧪', 'Running pre-deploy checks'],
    'live'                   => ['✅', 'Running and reachable'],
    'deactivated'            => ['💤', 'Replaced by a newer deploy'],
    'build_failed'           => ['❌', 'The build broke — check the logs'],
    'update_failed'          => ['❌', 'The new version failed to start'],
    'pre_deploy_failed'      => ['❌', 'A pre-deploy step failed'],
    'canceled'               => ['🚫', 'Stopped before it finished'],
    'unknown'                => ['❔', 'No deploy information yet'],
];

/** Response bodies that usually mean "this account is full" — safe to auto-rotate on. */
$LIMIT_HINTS = ['limit', 'quota', 'maximum number', 'upgrade your plan', 'plan limit', 'free instances'];

// ============================================================
//  2. LOW-LEVEL HTTP
// ============================================================
function http($method, $url, $headers = [], $body = null) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 30,
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $res  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $res];
}

// ============================================================
//  3. TELEGRAM HELPERS
// ============================================================
$CB_MSG_ID = null;   // message to edit, when the update came from a button
$CB_ID     = null;   // callback id, for the little toast popup

function esc($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/** A thin horizontal rule used to separate card sections. */
function rule() { return "━━━━━━━━━━━━━━━━━━"; }

function tg($method, $params) {
    global $TELEGRAM_TOKEN;
    [, $res] = http('POST', "https://api.telegram.org/bot$TELEGRAM_TOKEN/$method",
        ['Content-Type: application/json'], json_encode($params));
    return json_decode($res, true);
}

function say($chat, $text, $keyboard = null) {
    $p = ['chat_id' => $chat, 'text' => $text, 'parse_mode' => 'HTML', 'disable_web_page_preview' => true];
    if ($keyboard) $p['reply_markup'] = ['inline_keyboard' => $keyboard];
    return tg('sendMessage', $p);
}

function edit($chat, $mid, $text, $keyboard = null) {
    $p = ['chat_id' => $chat, 'message_id' => $mid, 'text' => $text,
          'parse_mode' => 'HTML', 'disable_web_page_preview' => true];
    if ($keyboard) $p['reply_markup'] = ['inline_keyboard' => $keyboard];
    $r = tg('editMessageText', $p);
    if (!($r['ok'] ?? false)) return say($chat, $text, $keyboard);   // fall back to a new message
    return $r;
}

/** Edit in place when triggered by a button, otherwise send a new message. */
function out($chat, $text, $keyboard = null) {
    global $CB_MSG_ID;
    if ($CB_MSG_ID) { $m = $CB_MSG_ID; $CB_MSG_ID = null; return edit($chat, $m, $text, $keyboard); }
    return say($chat, $text, $keyboard);
}

/** Small popup at the top of the screen — instant feedback on a tap. */
function toast($text = '', $alert = false) {
    global $CB_ID;
    if (!$CB_ID) return;
    tg('answerCallbackQuery', ['callback_query_id' => $CB_ID, 'text' => $text, 'show_alert' => $alert]);
    $CB_ID = null;
}

/**
 * Try to delete a message containing a secret.
 * Telegram does not always allow bots to delete user messages in private
 * chats, so this may quietly do nothing. Treat it as best-effort only.
 */
function wipe($chat, $mid) {
    if ($mid) tg('deleteMessage', ['chat_id' => $chat, 'message_id' => $mid]);
}

function mask($v) {
    $v = (string)$v; $len = mb_strlen($v);
    if ($len <= 8) return str_repeat('•', max($len, 3));
    return mb_substr($v, 0, 4) . str_repeat('•', 6) . mb_substr($v, -4);
}

function bar($done, $total) {
    $done = max(0, min($done, $total));
    return str_repeat('▰', $done) . str_repeat('▱', $total - $done);
}

function kb_main() {
    global $RENDER_ACCOUNTS;
    $rows = [
        [['text' => '🚀 New deployment', 'callback_data' => 'nav:newhost']],
        [['text' => '🗂 My sites', 'callback_data' => 'nav:sites'],
         ['text' => '📦 My repos', 'callback_data' => 'nav:repos']],
        [['text' => '📊 Status', 'callback_data' => 'nav:status'],
         ['text' => '📜 Logs',   'callback_data' => 'nav:logs']],
        [['text' => '🔀 Render accounts', 'callback_data' => 'nav:accounts']],
        [['text' => '🩺 Connection check', 'callback_data' => 'nav:check'],
         ['text' => '❓ Help', 'callback_data' => 'nav:help']],
    ];
    return $rows;
}
function kb_back() { return [[['text' => '🏠 Main menu', 'callback_data' => 'nav:menu']]]; }

/**
 * Registers the "/" command menu Telegram shows the admin when they tap
 * the little menu icon in the chat box. Cheap call, safe to repeat — we
 * only actually fire it once per bot restart via a state flag.
 */
function ensure_bot_commands() {
    $s = load_state();
    if (!empty($s['_commands_set_v3'])) return;

    tg('setMyCommands', ['commands' => [
        ['command' => 'menu',     'description' => '🏠 Main menu'],
        ['command' => 'newhost',  'description' => '🚀 Deploy a new client site'],
        ['command' => 'sites',    'description' => '🗂 Manage your live sites'],
        ['command' => 'repos',    'description' => '📦 Repos this bot has created'],
        ['command' => 'status',   'description' => '📊 Latest deploy status'],
        ['command' => 'logs',     'description' => '📜 Recent log lines'],
        ['command' => 'accounts', 'description' => '🔀 Your Render accounts & site counts'],
        ['command' => 'check',    'description' => '🩺 Test GitHub & Render connections'],
        ['command' => 'cancel',   'description' => '🛑 Abort the current wizard'],
        ['command' => 'help',     'description' => '❓ How this bot works'],
    ]]);

    $s = load_state();
    $s['_commands_set_v3'] = true;
    save_state($s);
}

// ============================================================
//  4. GITHUB & RENDER CLIENTS
// ============================================================
function gh($method, $path, $body = null) {
    global $GITHUB_TOKEN;
    $h = ["Authorization: Bearer $GITHUB_TOKEN", "User-Agent: render-deploy-bot",
          "Accept: application/vnd.github+json", "X-GitHub-Api-Version: 2022-11-28"];
    if ($body !== null) $h[] = "Content-Type: application/json";
    [$c, $r] = http($method, "https://api.github.com$path", $h, $body !== null ? json_encode($body) : null);
    return [$c, json_decode($r, true)];
}

/** account() resolves an index (or 'auto' → first) to its credentials. */
function account($idx) {
    global $RENDER_ACCOUNTS;
    if ($idx === 'auto' || $idx === null) $idx = 0;
    $idx = (int)$idx;
    return $RENDER_ACCOUNTS[$idx] ?? ($RENDER_ACCOUNTS[0] ?? null);
}

function account_name($idx) {
    $a = account($idx);
    return $a ? $a['name'] : 'Unknown account';
}

/** Ask Render which owner(s) an API key belongs to. */
function render_owners_for_key($key) {
    $h = ["Authorization: Bearer $key", "Accept: application/json"];
    [$c, $r] = http('GET', "https://api.render.com/v1/owners?limit=20", $h);
    $data = json_decode($r, true);
    if ($c !== 200 || !is_array($data)) return [$c, []];
    $owners = [];
    foreach ($data as $item) {
        $o = $item['owner'] ?? $item;
        $owners[] = ['id' => $o['id'] ?? '', 'name' => $o['name'] ?? '', 'email' => $o['email'] ?? '', 'type' => $o['type'] ?? ''];
    }
    return [200, $owners];
}

/** The email tied to this account — stored if we already know it, looked up otherwise. */
function account_email($idx) {
    $a = account($idx);
    if (!$a) return '';
    if (!empty($a['email'])) return $a['email'];
    [, $owners] = render_owners_for_key($a['key']);
    foreach ($owners as $o) {
        if (empty($a['owner_id']) || $o['id'] === $a['owner_id']) return $o['email'] ?: $o['name'];
    }
    return '';
}

/**
 * Which account "Auto" uses next. Simple round-robin across every
 * configured account, remembered between deployments — Render's own
 * plan limit is what actually decides whether an attempt succeeds; this
 * just spreads new sites out instead of always hammering account #1.
 */
function pick_best_account() {
    global $RENDER_ACCOUNTS, $STATE_FILE;
    if (!$RENDER_ACCOUNTS) return null;
    $s = file_exists($STATE_FILE) ? (json_decode(file_get_contents($STATE_FILE), true) ?: []) : [];
    $last = $s['_last_auto_acct'] ?? -1;
    return ((int)$last + 1) % count($RENDER_ACCOUNTS);
}
function remember_auto_account($idx) {
    $s = load_state();
    $s['_last_auto_acct'] = $idx;
    save_state($s);
}

/** All Render calls take an explicit account index — never a hidden default. */
function rnd($method, $path, $body = null, $acctIdx = 0) {
    $acct = account($acctIdx);
    if (!$acct) return [0, ['message' => 'No Render account configured']];
    $h = ["Authorization: Bearer {$acct['key']}", "Accept: application/json"];
    if ($body !== null) $h[] = "Content-Type: application/json";
    [$c, $r] = http($method, "https://api.render.com/v1$path", $h, $body !== null ? json_encode($body) : null);
    return [$c, json_decode($r, true)];
}

function render_services($acctIdx = 0) {
    $acct = account($acctIdx);
    if (!$acct) return [0, []];
    [$c, $svcs] = rnd('GET', "/services?ownerId={$acct['owner_id']}&limit=100", null, $acctIdx);
    if ($c !== 200 || !is_array($svcs)) return [$c, []];
    $list = [];
    foreach ($svcs as $item) {
        $svc = $item['service'] ?? $item;
        if (($svc['type'] ?? '') !== 'web_service') continue;
        $svc['_acct'] = $acctIdx;
        $list[] = $svc;
    }
    return [200, $list];
}

/** Services across every configured account, each tagged with its account index. */
function render_services_all() {
    global $RENDER_ACCOUNTS;
    $all = [];
    foreach ($RENDER_ACCOUNTS as $i => $a) {
        [, $list] = render_services($i);
        foreach ($list as $s) $all[] = $s;
    }
    return $all;
}

/** True-ish if a failed Render response looks like a plan/service limit, not a real error. */
function looks_like_limit($body) {
    global $LIMIT_HINTS;
    $s = strtolower(is_array($body) ? json_encode($body) : (string)$body);
    foreach ($LIMIT_HINTS as $hint) if (strpos($s, $hint) !== false) return true;
    return false;
}

// ============================================================
//  5. STATE  (+ duplicate-update protection)
// ============================================================
function load_state() {
    global $STATE_FILE;
    return file_exists($STATE_FILE) ? (json_decode(file_get_contents($STATE_FILE), true) ?: []) : [];
}
function save_state($s) {
    global $STATE_FILE;
    file_put_contents($STATE_FILE, json_encode($s));
}
/** Clear the wizard but remember which service/account we are watching. */
function reset_flow($chat) {
    $s = load_state();
    $keepSid  = $s[$chat]['service_id']   ?? null;
    $keepAcct = $s[$chat]['service_acct'] ?? null;
    $seen = $s['_seen'] ?? [];
    $s[$chat] = ['step' => 'idle'];
    if ($keepSid !== null)  $s[$chat]['service_id']   = $keepSid;
    if ($keepAcct !== null) $s[$chat]['service_acct'] = $keepAcct;
    $s['_seen'] = $seen;
    save_state($s);
}
/** True if this update_id was already handled (Telegram retries on timeout). */
function already_seen($update_id) {
    if (!$update_id) return false;
    $s = load_state();
    $seen = $s['_seen'] ?? [];
    if (in_array($update_id, $seen, true)) return true;
    $seen[] = $update_id;
    $s['_seen'] = array_slice($seen, -40);
    save_state($s);
    return false;
}

// ============================================================
//  6. NAME SANITISING & AVAILABILITY
// ============================================================
/** GitHub allows letters, digits, dot, underscore, hyphen. Everything else becomes a hyphen. */
function sanitize_repo_name($raw) {
    $n = preg_replace('/[^A-Za-z0-9._-]+/', '-', trim((string)$raw));
    $n = preg_replace('/-{2,}/', '-', $n);
    return mb_substr(trim($n, '-._'), 0, 90);
}
/** Render allows lowercase letters, digits and hyphens. */
function sanitize_service_name($raw) {
    $n = strtolower(preg_replace('/[^A-Za-z0-9-]+/', '-', trim((string)$raw)));
    return trim(preg_replace('/-{2,}/', '-', $n), '-');
}
function repo_exists($owner, $name) {
    [$c] = gh('GET', "/repos/$owner/$name");
    return $c === 200;
}
/** If "avea-zae" is taken on the target account, try "avea-zae-2", "-3", and so on. */
function free_service_name($base, $acctIdx) {
    [, $svcs] = render_services($acctIdx);
    $taken = [];
    foreach ($svcs as $s) $taken[strtolower($s['name'] ?? '')] = true;
    $name = $base; $i = 2;
    while (isset($taken[$name]) && $i < 50) { $name = $base . '-' . $i; $i++; }
    return $name;
}

// ============================================================
//  7. REPOSITORY CLONING
// ============================================================
/** Flag the source repo as a GitHub template if it isn't one already. */
function ensure_template($repo) {
    [$c, $r] = gh('GET', "/repos/$repo");
    if ($c !== 200) return [false, "could not read the repo (HTTP $c)"];
    if (!empty($r['is_template'])) return [true, null];
    [$c2] = gh('PATCH', "/repos/$repo", ['is_template' => true]);
    if ($c2 < 200 || $c2 >= 300) return [false, "could not mark it as a template (HTTP $c2)"];
    return [true, null];
}

/** Preferred path: GitHub's "generate from template" endpoint. */
function clone_via_template($srcRepo, $owner, $newName, $private, $desc) {
    [$ok, $err] = ensure_template($srcRepo);
    if (!$ok) return [0, null, $err];
    [$c, $r] = gh('POST', "/repos/$srcRepo/generate", [
        'owner' => $owner, 'name' => $newName, 'private' => (bool)$private,
        'description' => $desc, 'include_all_branches' => false,
    ]);
    if ($c < 200 || $c >= 300) return [$c, null, substr(json_encode($r), 0, 250)];
    return [$c, $r, null];
}

/**
 * Fallback: create an empty repo and copy the whole tree file by file.
 * Slower and capped, but works when the template route is unavailable.
 */
function clone_via_copy($srcRepo, $owner, $newName, $private, $branch, $desc) {
    global $MAX_COPY_FILES;

    [$ct, $tree] = gh('GET', "/repos/$srcRepo/git/trees/" . rawurlencode($branch) . "?recursive=1");
    if ($ct !== 200 || empty($tree['tree'])) return [0, null, "could not read the file tree (HTTP $ct)"];

    $blobs = [];
    foreach ($tree['tree'] as $e) {
        if (($e['type'] ?? '') !== 'blob') continue;
        $blobs[] = $e;
        if (count($blobs) > $MAX_COPY_FILES) return [0, null, "too many files (over $MAX_COPY_FILES)"];
    }
    if (!$blobs) return [0, null, "the template repo is empty"];

    [$cr, $repo] = gh('POST', '/user/repos', [
        'name' => $newName, 'private' => (bool)$private,
        'description' => $desc, 'auto_init' => false,
    ]);
    if ($cr < 200 || $cr >= 300) return [$cr, null, substr(json_encode($repo), 0, 250)];

    $newFull = "$owner/$newName";
    $entries = [];
    foreach ($blobs as $e) {
        [$cb, $blob] = gh('GET', "/repos/$srcRepo/git/blobs/{$e['sha']}");
        if ($cb !== 200 || !isset($blob['content'])) continue;
        [$cn, $nb] = gh('POST', "/repos/$newFull/git/blobs", [
            'content' => str_replace("\n", '', $blob['content']), 'encoding' => 'base64',
        ]);
        if ($cn < 200 || $cn >= 300) continue;
        $entries[] = ['path' => $e['path'], 'mode' => $e['mode'], 'type' => 'blob', 'sha' => $nb['sha']];
    }
    if (!$entries) return [0, null, "no files could be copied"];

    [$c1, $nt] = gh('POST', "/repos/$newFull/git/trees", ['tree' => $entries]);
    if ($c1 < 200 || $c1 >= 300) return [$c1, null, "could not build the file tree"];
    [$c2, $nc] = gh('POST', "/repos/$newFull/git/commits", [
        'message' => "init: copied from $srcRepo", 'tree' => $nt['sha'], 'parents' => [],
    ]);
    if ($c2 < 200 || $c2 >= 300) return [$c2, null, "could not create the first commit"];
    [$c3] = gh('POST', "/repos/$newFull/git/refs", ['ref' => 'refs/heads/main', 'sha' => $nc['sha']]);
    if ($c3 < 200 || $c3 >= 300) return [$c3, null, "could not create the main branch"];

    return [201, ['full_name' => $newFull, 'default_branch' => 'main',
                  'html_url' => "https://github.com/$newFull"], null];
}

/** New repos are populated asynchronously, so poll until the config file appears. */
function wait_for_file($repo, $branch, $path, $tries = 6) {
    for ($i = 0; $i < $tries; $i++) {
        [$c, $f] = gh('GET', "/repos/$repo/contents/" . rawurlencode($path) . "?ref=$branch");
        if ($c === 200 && !empty($f['content'])) return $f;
        sleep(2);
    }
    return null;
}

// ============================================================
//  8. CONFIG FILE REWRITER
// ============================================================
/**
 * Replace `$var = "…";` or `$var = '…';`
 * The new value is written in SINGLE quotes so that values containing `$`
 * are not interpreted as PHP variables.
 */
function replace_var($content, $var, $value) {
    $q = preg_quote($var, '/');
    $pattern = '/(\$' . $q . '\s*=\s*)("(?:[^"\\\\]|\\\\.)*"|\'(?:[^\'\\\\]|\\\\.)*\')(\s*;)/';
    $count = 0;
    $out = preg_replace_callback($pattern, function ($m) use ($value) {
        return $m[1] . "'" . addcslashes($value, "\\'") . "'" . $m[3];
    }, $content, 1, $count);
    return ($out !== null && $count > 0) ? [$out, true] : [$content, false];
}

// ============================================================
//  9. MENU, HELP, DIAGNOSTICS
// ============================================================
function show_menu($chat) {
    global $RENDER_ACCOUNTS;

    if (!$RENDER_ACCOUNTS) {
        out($chat,
            "🏠 <b>Deploy Bot</b>\n" . rule() . "\n" .
            "I copy one of your GitHub repos, drop in a fresh <code>config.php</code>, " .
            "and put the copy live on Render.\n\n" .
            "⚠️ <b>No Render account connected yet.</b>\n" .
            "Set <code>RENDER_ACCOUNTS</code> in this bot's Environment tab on Render, then " .
            "send /help for the exact format.",
            [[['text' => '❓ Help', 'callback_data' => 'nav:help']]]);
        return;
    }

    $acctLine = count($RENDER_ACCOUNTS) > 1
        ? "🔀 <b>" . count($RENDER_ACCOUNTS) . " Render accounts</b> connected — pick one anytime, or view all together.\n\n"
        : '';
    out($chat,
        "🏠 <b>Deploy Bot</b>\n" . rule() . "\n" .
        "I copy one of your GitHub repos, drop in a fresh <code>config.php</code>, " .
        "and put the copy live on Render.\n\n" .
        "Your original repo is never edited — every client gets their own copy.\n\n" .
        $acctLine .
        "👇 <i>Pick something below, or type a command.</i>",
        kb_main());
}

function show_help($chat) {
    global $RENDER_ACCOUNTS, $GITHUB_OWNER;
    $multi = count($RENDER_ACCOUNTS) > 1;
    out($chat,
        "❓ <b>How this bot works</b>\n" . rule() . "\n\n" .
        "<b>The idea</b>\n" .
        "One repo is your <i>template</i>. Each client gets a copy of it with their " .
        "own settings, running as its own site.\n\n" .
        "<code>AveaBeauty</code>  ← template, untouched\n" .
        "   ├─ <code>AveaBeauty-Zae</code>  → site 1\n" .
        "   └─ <code>AveaBeauty-Mika</code> → site 2\n\n" .
        "<b>The wizard, step by step</b>\n" .
        "1️⃣ Pick the template repo to copy\n" .
        "2️⃣ Name the new repo (e.g. <code>AveaBeauty-Zae</code>)\n" .
        ($multi ? "3️⃣ Choose which Render account to deploy to\n4️⃣ Answer the config questions one at a time\n5️⃣ Review everything, then confirm\n\n"
                : "3️⃣ Answer the config questions one at a time\n4️⃣ Review everything, then confirm\n\n") .
        "Nothing is created until you tap <b>Create &amp; deploy</b> in the last step. " .
        "You can back out with ❌ Cancel at any point.\n\n" .
        "<b>Render accounts</b>\n" .
        "You currently have <b>" . count($RENDER_ACCOUNTS) . "</b> connected. Add or remove accounts " .
        "by editing <code>RENDER_ACCOUNTS</code> in this bot's Render → Environment tab — it's a " .
        "JSON list:\n\n" .
        "<pre>[\n  {\"name\":\"Main\",\"key\":\"rnd_xxx\"},\n  {\"name\":\"Backup\",\"key\":\"rnd_yyy\"}\n]</pre>\n" .
        "Just the <code>key</code> is required (Full Access, not Read Only — from Render → Account " .
        "Settings → API Keys). Owner ID and email are looked up automatically, no need to include them.\n\n" .
        "Save the variable, let this bot redeploy, and every account in the list shows up here " .
        "immediately — in <code>/accounts</code>, <code>/sites</code>, and the deploy wizard.\n\n" .
        "No \"limit\" setting on purpose — Render enforces its own plan limits. Everywhere you view " .
        "sites you pick an account first (or 🌐 All accounts to see everything together). When " .
        "deploying, 🎲 <b>Auto</b> is the default: it rotates across your accounts, and the moment " .
        "Render itself says the current one is full it silently moves to the next.\n\n" .
        "<b>Commands</b>\n" .
        "🚀 <code>/newhost</code> — start the wizard\n" .
        "🗂 <code>/sites</code> — redeploy, restart, stop or delete a site\n" .
        "📦 <code>/repos</code> — repos this bot has created\n" .
        "📊 <code>/status</code> — how the latest deploy is doing\n" .
        "📜 <code>/logs</code> — last 40 log lines\n" .
        "🔀 <code>/accounts</code> — your connected Render accounts\n" .
        "🩺 <code>/check</code> — test the GitHub and Render connections\n" .
        "🛑 <code>/cancel</code> — abort the wizard\n" .
        "🏠 <code>/menu</code> — back to the main menu\n\n" .
        "<b>One-time setup to check</b>\n" .
        "On GitHub go to <i>Settings → Applications → Render</i> and give it access to " .
        "<b>All repositories</b>. Do this for <u>every</u> Render account you connect — otherwise " .
        "that account can't see the new repos this bot makes.",
        kb_back());
}

/** Quick health check so a failure later is easier to diagnose. */
function show_check($chat) {
    global $GITHUB_OWNER, $RENDER_ACCOUNTS, $RENDER_REGION, $CONFIG_PATH, $ADMIN_CHAT_ID;
    out($chat, "🩺 Running checks…");

    $lines = [];

    [$c1, $me] = gh('GET', '/user');
    $lines[] = ($c1 === 200)
        ? "✅ <b>GitHub</b> — signed in as <code>" . esc($me['login']) . "</code>"
        : "❌ <b>GitHub</b> — token rejected (HTTP " . esc($c1) . ")\n     <i>Check GITHUB_TOKEN has the “repo” scope.</i>";

    if ($c1 === 200 && strcasecmp($me['login'], (string)$GITHUB_OWNER) !== 0) {
        $lines[] = "⚠️ <b>Owner mismatch</b> — GITHUB_OWNER is <code>" . esc($GITHUB_OWNER) .
                   "</code> but the token belongs to <code>" . esc($me['login']) . "</code>";
    }

    if (!$RENDER_ACCOUNTS) {
        $lines[] = "❌ <b>Render</b> — no account connected\n     <i>Set RENDER_ACCOUNTS in Environment, then send /help.</i>";
    } else {
        foreach ($RENDER_ACCOUNTS as $i => $a) {
            [$c2, $svcs] = render_services($i);
            $lines[] = ($c2 === 200)
                ? "✅ <b>Render — " . esc($a['name']) . "</b> — connected, " . count($svcs) . " web service(s)"
                : "❌ <b>Render — " . esc($a['name']) . "</b> — request failed (HTTP " . esc($c2) . ")\n     <i>Check its key is Full Access and still valid.</i>";
        }
    }

    $lines[] = "📄 Config file: <code>" . esc($CONFIG_PATH) . "</code>";
    $lines[] = "🌏 Region: <code>" . esc($RENDER_REGION) . "</code>";
    $lines[] = "🔐 Admin chat: <code>" . esc($ADMIN_CHAT_ID) . "</code>";

    say($chat,
        "🩺 <b>Connection check</b>\n" . rule() . "\n" . implode("\n", $lines) . "\n\n" .
        "<i>If GitHub and every Render account show ✅, the wizard should work end to end.</i>",
        kb_main());
}

// ============================================================
//  10. RENDER ACCOUNTS — VIEW / PICK
// ============================================================
function show_accounts($chat) {
    global $RENDER_ACCOUNTS;
    if (!$RENDER_ACCOUNTS) {
        out($chat, "⚠️ <b>No Render account connected yet</b>\n\n" .
                   "<i>Set RENDER_ACCOUNTS (or RENDER_API_KEY) in this bot's Render → Environment tab, " .
                   "then send /help for the exact format.</i>", kb_back());
        return;
    }

    out($chat, "🔀 Checking your accounts…");

    $lines = [];
    $totalSites = 0;
    foreach ($RENDER_ACCOUNTS as $i => $a) {
        [$c, $svcs] = render_services($i);
        $email = account_email($i);
        if ($c !== 200) {
            $lines[] = "🔀 <b>" . esc($a['name']) . "</b>\n     ❌ connection error (HTTP " . esc($c) . ")";
            continue;
        }
        $count = count($svcs);
        $totalSites += $count;
        $lines[] = "🔀 <b>" . esc($a['name']) . "</b>\n" .
                   "     📧 " . esc($email ?: '—') . "\n" .
                   "     🗂 <code>$count</code> site(s)";
    }

    $best = pick_best_account();
    say($chat,
        "🔀 <b>Render accounts</b>  <i>(" . count($RENDER_ACCOUNTS) . " · $totalSites site(s) total)</i>\n" .
        rule() . "\n" . implode("\n\n", $lines) . "\n\n" . rule() . "\n" .
        "🎲 <i>Next auto-deploy would use:</i> <b>" . esc(account_name($best)) . "</b>\n\n" .
        "<i>Add or remove accounts anytime by editing RENDER_ACCOUNTS in this bot's Environment tab.</i>",
        [[['text' => '🗂 Browse my sites', 'callback_data' => 'nav:sites']],
         [['text' => '🚀 New deployment', 'callback_data' => 'nav:newhost']],
         kb_back()[0]]);
}

/**
 * Account picker shown before any "which sites do you want to see" action.
 * $purpose becomes part of the callback_data so we know where to go next
 * once an account (or "all") is picked — see acctsel: handling below.
 */
function ask_account($chat, $purpose) {
    global $RENDER_ACCOUNTS;
    if (count($RENDER_ACCOUNTS) <= 1) {
        // Nothing to pick — go straight to the destination with account 0.
        route_after_account($chat, $purpose, 0);
        return;
    }
    $rows = [];
    foreach ($RENDER_ACCOUNTS as $i => $a) {
        $rows[] = [['text' => "🔀 " . $a['name'], 'callback_data' => "acctsel:$purpose:$i"]];
    }
    $rows[] = [['text' => '🌐 All accounts', 'callback_data' => "acctsel:$purpose:all"]];
    $rows[] = [['text' => '🏠 Main menu', 'callback_data' => 'nav:menu']];
    out($chat, "🔀 <b>Which Render account?</b>\n\n<i>Tap one, or show everything together.</i>", $rows);
}

function route_after_account($chat, $purpose, $acctSel) {
    if ($purpose === 'sites') list_services($chat, 0, $acctSel);
}

// ============================================================
//  11. STEP 1 — CHOOSE THE TEMPLATE REPO
// ============================================================
function list_repos($chat, $page = 0, $filter = '') {
    global $PAGE_SIZE;
    $state = load_state();

    $cached = $state[$chat]['repo_cache'] ?? null;
    if (!$cached) {
        out($chat, "⏳ Loading your GitHub repositories…");
        [$code, $repos] = gh('GET', '/user/repos?per_page=100&sort=updated&affiliation=owner');
        if ($code !== 200 || !is_array($repos)) {
            say($chat, "❌ <b>Couldn't load your repos</b> (HTTP " . esc($code) . ").\n" .
                       "<i>Run /check to test the GitHub connection.</i>", kb_back());
            return;
        }
        $cached = [];
        foreach ($repos as $r) {
            $cached[] = [
                'full'     => $r['full_name'],
                'branch'   => $r['default_branch'],
                'private'  => (bool)($r['private'] ?? false),
                'template' => (bool)($r['is_template'] ?? false),
            ];
        }
        $state = load_state();
        $state[$chat]['repo_cache'] = $cached;
    }

    $state[$chat]['step'] = 'choose_repo';
    save_state($state);

    $items = [];
    foreach ($cached as $i => $r) {
        if ($filter !== '' && stripos($r['full'], $filter) === false) continue;
        $items[] = [$i, $r];
    }
    if (!$items) {
        out($chat, "🔍 <b>No repos match “" . esc($filter) . "”</b>\n\n" .
                   "<i>Try a shorter word, or show everything again.</i>",
            [[['text' => '↩️ Show all repos', 'callback_data' => 'repopg:0:']],
             [['text' => '🏠 Main menu', 'callback_data' => 'nav:menu']]]);
        return;
    }

    $total = count($items);
    $pages = (int)ceil($total / $PAGE_SIZE);
    $page  = max(0, min($page, $pages - 1));

    $rows = [];
    foreach (array_slice($items, $page * $PAGE_SIZE, $PAGE_SIZE) as [$i, $r]) {
        $icon = $r['template'] ? '🧩' : ($r['private'] ? '🔒' : '📂');
        $rows[] = [['text' => "$icon " . $r['full'], 'callback_data' => "repo:$i"]];
    }
    $nav = [];
    if ($page > 0)          $nav[] = ['text' => '◀️ Back', 'callback_data' => "repopg:" . ($page - 1) . ":$filter"];
    if ($pages > 1)         $nav[] = ['text' => "📄 " . ($page + 1) . " / $pages", 'callback_data' => 'noop'];
    if ($page < $pages - 1) $nav[] = ['text' => 'Next ▶️', 'callback_data' => "repopg:" . ($page + 1) . ":$filter"];
    if ($nav) $rows[] = $nav;
    $rows[] = [['text' => '🔍 Search', 'callback_data' => 'reposearch'],
               ['text' => '❌ Cancel', 'callback_data' => 'cancel']];

    $head  = "🚀 <b>New deployment</b>   <i>step 1 of " . wizard_total_steps() . "</i>\n" . bar(1, wizard_total_steps()) . "\n" . rule() . "\n";
    $head .= "🧩 <b>Which repo should I copy?</b>\n\n";
    $head .= "This one stays exactly as it is — I only make a copy of it.\n\n";
    $head .= "<i>🧩 already a template · 🔒 private · 📂 public</i>";
    if ($filter !== '') $head .= "\n\n🔍 Filter: <code>" . esc($filter) . "</code>";
    $head .= "\n\n<i>Showing $total repo(s), newest first.</i>";
    out($chat, $head, $rows);
}

/** Wizard has one extra step when there's more than one Render account to pick from. */
function wizard_total_steps() {
    global $RENDER_ACCOUNTS;
    return count($RENDER_ACCOUNTS) > 1 ? 5 : 4;
}

// ============================================================
//  12. STEP 2 — NAME THE NEW REPO
// ============================================================
function ask_repo_name($chat) {
    global $DEFAULT_PRIVATE;
    $s    = load_state();
    $src  = $s[$chat]['src_repo'];
    $base = explode('/', $src)[1] ?? $src;
    $priv = $s[$chat]['private'] ?? $DEFAULT_PRIVATE;

    out($chat,
        "🚀 <b>New deployment</b>   <i>step 2 of " . wizard_total_steps() . "</i>\n" . bar(2, wizard_total_steps()) . "\n" . rule() . "\n" .
        "🧩 Template: <code>" . esc($src) . "</code>\n" .
        "🌿 Branch: <code>" . esc($s[$chat]['src_branch']) . "</code>\n" .
        rule() . "\n" .
        "🏷 <b>What should the new repo be called?</b>\n\n" .
        "✏️ Type a full name, for example:\n" .
        "     <code>" . esc($base) . "-Zae</code>\n\n" .
        "💡 Or just type the client's name — <code>Zae</code> — and I'll turn it into " .
        "<code>" . esc($base) . "-Zae</code> for you.\n\n" .
        "ℹ️ <i>GitHub doesn't allow <code>@</code> or spaces, so I'll swap those for hyphens " .
        "and show you the final name before anything is created.</i>\n\n" .
        "👁 Visibility is set to " . ($priv ? "<b>private</b>" : "<b>public</b>") .
        " — tap the button below to switch.",
        [[['text' => $priv ? '🔒 Private  (tap for public)' : '🌐 Public  (tap for private)',
           'callback_data' => 'togpriv']],
         [['text' => '↩️ Pick another repo', 'callback_data' => 'repopg:0:'],
          ['text' => '❌ Cancel', 'callback_data' => 'cancel']]]);
}

function set_repo_name($chat, $raw) {
    global $GITHUB_OWNER, $DEFAULT_PRIVATE;
    $s    = load_state();
    $src  = $s[$chat]['src_repo'];
    $base = explode('/', $src)[1] ?? $src;

    $typed = trim((string)$raw);
    if ($typed === '') { say($chat, "⚠️ I need a name — type one and I'll check if it's free."); return; }

    // A short entry that doesn't include the template name is treated as a suffix.
    $wanted = (stripos($typed, $base) === false && mb_strlen($typed) <= 20) ? "$base-$typed" : $typed;
    $name   = sanitize_repo_name($wanted);

    if (mb_strlen($name) < 3) {
        say($chat, "⚠️ That's too short — GitHub needs at least 3 characters.");
        return;
    }
    if ($name !== $wanted) {
        say($chat, "✏️ Adjusted to <code>" . esc($name) . "</code>\n" .
                   "<i>“" . esc($wanted) . "” contained characters GitHub doesn't accept.</i>");
    }
    if (repo_exists($GITHUB_OWNER, $name)) {
        out($chat, "⚠️ <b>That name is taken</b>\n\n" .
                   "<code>" . esc($GITHUB_OWNER) . "/" . esc($name) . "</code> already exists.\n\n" .
                   "<i>Type a different one — try adding a number or the client's surname.</i>",
            [[['text' => '❌ Cancel', 'callback_data' => 'cancel']]]);
        return;
    }

    $s = load_state();
    $s[$chat]['new_name']  = $name;
    $s[$chat]['private']   = $s[$chat]['private'] ?? $DEFAULT_PRIVATE;
    $s[$chat]['acct_sel']  = $s[$chat]['acct_sel'] ?? 'auto';
    $s[$chat]['step']      = 'collect';
    $s[$chat]['field_idx'] = 0;
    $s[$chat]['values']    = [];
    save_state($s);

    say($chat, "✅ <b>Name reserved</b>\n📦 <code>" . esc($GITHUB_OWNER) . "/" . esc($name) . "</code>\n\n" .
               "<i>Nothing has been created yet — that happens at the end.</i>");

    ask_deploy_account($chat);
}

// ============================================================
//  12b. STEP 2.5 — WHICH RENDER ACCOUNT TO DEPLOY TO
// ============================================================
function ask_deploy_account($chat) {
    global $RENDER_ACCOUNTS;

    if (count($RENDER_ACCOUNTS) <= 1) {
        // Only one account exists — nothing to choose, skip straight ahead.
        ask_next_field($chat);
        return;
    }

    $s   = load_state();
    $sel = $s[$chat]['acct_sel'] ?? 'auto';
    $label = ($sel === 'auto') ? '🎲 Auto — spreads across accounts' : ('🔀 ' . account_name($sel));

    $rows = [];
    foreach ($RENDER_ACCOUNTS as $i => $a) {
        $rows[] = [['text' => ($sel === (string)$i ? '✅ ' : '') . $a['name'], 'callback_data' => "setacct:$i"]];
    }
    $rows[] = [['text' => ($sel === 'auto' ? '✅ ' : '') . '🎲 Auto — spread across accounts (default)', 'callback_data' => 'setacct:auto']];
    $rows[] = [['text' => '➡️ Continue', 'callback_data' => 'acctdone']];
    $rows[] = [['text' => '❌ Cancel', 'callback_data' => 'cancel']];

    out($chat,
        "🚀 <b>New deployment</b>   <i>step 3 of " . wizard_total_steps() . "</i>\n" . bar(3, wizard_total_steps()) . "\n" . rule() . "\n" .
        "🔀 <b>Which Render account should host this site?</b>\n\n" .
        "Currently selected: <b>" . esc($label) . "</b>\n\n" .
        "<i>🎲 Auto rotates across your connected accounts, and moves to the next one right away " .
        "if Render says the current one is full.</i>",
        $rows);
}

// ============================================================
//  13. STEP 3/4 — COLLECT CONFIG VALUES
// ============================================================
function collect_step_num() { return wizard_total_steps() === 5 ? 4 : 3; }

function ask_next_field($chat) {
    global $CONFIG_FIELDS;
    $s     = load_state();
    $keys  = array_keys($CONFIG_FIELDS);
    $idx   = $s[$chat]['field_idx'] ?? 0;
    $total = count($keys);
    if ($idx >= $total) { review($chat); return; }

    $key = $keys[$idx];
    $f   = $CONFIG_FIELDS[$key];

    $btns = [];
    if (empty($f['required'])) $btns[] = ['text' => '⏭ Skip this one', 'callback_data' => 'skip'];
    $btns[] = ['text' => '❌ Cancel', 'callback_data' => 'cancel'];

    $note = !empty($f['required'])
        ? "🔴 <i>Required — this one can't be skipped.</i>"
        : "⚪ <i>Optional — skipping keeps the template's existing value.</i>";

    $step = collect_step_num();
    out($chat,
        "🚀 <b>New deployment</b>   <i>step $step of " . wizard_total_steps() . "</i>\n" . bar($step, wizard_total_steps()) . "\n" . rule() . "\n" .
        "⚙️ <b>Setting " . ($idx + 1) . " of $total</b>\n\n" .
        "{$f['emoji']} <b>" . esc($f['label']) . "</b>\n" .
        "Goes into <code>\$" . esc($key) . "</code>\n\n" .
        "💡 " . esc($f['hint']) . "\n\n" .
        $note . "\n" .
        (!empty($f['secret'])
            ? "🔐 <i>I'll try to delete your message afterwards so the value doesn't sit in the chat.</i>\n"
            : "") .
        "\n✏️ <b>Type the value now:</b>",
        [$btns]);
}

function take_field($chat, $text, $msg_id) {
    global $CONFIG_FIELDS;
    $s    = load_state();
    $keys = array_keys($CONFIG_FIELDS);
    $idx  = $s[$chat]['field_idx'] ?? 0;
    if ($idx >= count($keys)) { review($chat); return; }

    $key = $keys[$idx];
    $f   = $CONFIG_FIELDS[$key];
    $val = trim((string)$text);

    if ($val === '' || strtolower($val) === 'skip') {
        if (!empty($f['required'])) {
            say($chat, "⚠️ <b>" . esc($f['label']) . "</b> is required — the site won't run without it.\n" .
                       "<i>Let's try that one again.</i>");
            ask_next_field($chat);
            return;
        }
        $s[$chat]['field_idx'] = $idx + 1;
        save_state($s);
        say($chat, "⏭ Skipped <b>" . esc($f['label']) . "</b> — keeping the template's value.");
        ask_next_field($chat);
        return;
    }

    if (!empty($f['secret'])) wipe($chat, $msg_id);

    $s[$chat]['values'][$key] = $val;
    $s[$chat]['field_idx']    = $idx + 1;
    save_state($s);

    say($chat, "✅ <b>" . esc($f['label']) . "</b> saved\n" .
               "<code>" . esc(!empty($f['secret']) ? mask($val) : $val) . "</code>");
    ask_next_field($chat);
}

function skip_field($chat) {
    global $CONFIG_FIELDS;
    $s    = load_state();
    $keys = array_keys($CONFIG_FIELDS);
    $idx  = $s[$chat]['field_idx'] ?? 0;
    if ($idx >= count($keys)) { review($chat); return; }
    if (!empty($CONFIG_FIELDS[$keys[$idx]]['required'])) {
        toast("This one is required — the site won't run without it.", true);
        return;
    }
    toast('Skipped');
    $s[$chat]['field_idx'] = $idx + 1;
    save_state($s);
    ask_next_field($chat);
}

// ============================================================
//  14. FINAL STEP — REVIEW BEFORE ANYTHING IS CREATED
// ============================================================
function review($chat) {
    global $CONFIG_FIELDS, $CONFIG_PATH, $RENDER_REGION, $GITHUB_OWNER, $RENDER_ACCOUNTS;
    $s = load_state();
    $s[$chat]['step'] = 'review';
    save_state($s);

    $lines = '';
    foreach ($CONFIG_FIELDS as $key => $f) {
        if (!isset($s[$chat]['values'][$key])) {
            $lines .= "   {$f['emoji']} " . esc($f['label']) . " — <i>unchanged</i>\n";
        } else {
            $v = $s[$chat]['values'][$key];
            $lines .= "   {$f['emoji']} " . esc($f['label']) . " — <code>" .
                      esc(!empty($f['secret']) ? mask($v) : $v) . "</code>\n";
        }
    }
    $svcName = sanitize_service_name($s[$chat]['new_name']);
    $acctSel = $s[$chat]['acct_sel'] ?? 'auto';
    $acctLabel = ($acctSel === 'auto')
        ? '🎲 Auto (' . implode(' → ', array_column($RENDER_ACCOUNTS, 'name')) . ')'
        : '🔀 ' . account_name($acctSel);

    $total = wizard_total_steps();
    out($chat,
        "🚀 <b>New deployment</b>   <i>step $total of $total</i>\n" . bar($total, $total) . "\n" . rule() . "\n" .
        "📋 <b>Ready — please review</b>\n\n" .
        "🧩 Copy from: <code>" . esc($s[$chat]['src_repo']) . "</code>\n" .
        "         ↓\n" .
        "📦 New repo: <code>" . esc($GITHUB_OWNER) . "/" . esc($s[$chat]['new_name']) . "</code>\n" .
        "👁 Visibility: " . (($s[$chat]['private'] ?? true) ? '🔒 private' : '🌐 public') . "\n" .
        "🏷 Render service: <code>" . esc($svcName) . "</code>\n" .
        (count($RENDER_ACCOUNTS) > 1 ? "🔀 Render account: " . esc($acctLabel) . "\n" : '') .
        "🌏 Region: <code>" . esc($RENDER_REGION) . "</code>\n" .
        rule() . "\n" .
        "⚙️ <b>" . esc($CONFIG_PATH) . " changes</b>\n" . $lines .
        rule() . "\n" .
        "<b>What happens when you confirm</b>\n" .
        "1. Copy the template into the new repo\n" .
        "2. Write these values into <code>" . esc($CONFIG_PATH) . "</code>\n" .
        "3. Create the Render service and start the first build\n\n" .
        "🔒 <i>The template repo is not modified at any point.</i>",
        [[['text' => '✅ Create & deploy', 'callback_data' => 'deploy']],
         [['text' => '🔁 Redo the settings', 'callback_data' => 'redo'],
          ['text' => '❌ Cancel', 'callback_data' => 'cancel']]]);
}

// ============================================================
//  15. BUILD: CLONE → CONFIG → DEPLOY  (with account auto-rotate)
// ============================================================
function build_everything($chat) {
    global $GITHUB_OWNER, $CONFIG_PATH, $RENDER_REGION, $FIELD_ALIASES, $RENDER_ACCOUNTS;

    $s      = load_state();
    $src    = $s[$chat]['src_repo']   ?? null;
    $branch = $s[$chat]['src_branch'] ?? null;
    $name   = $s[$chat]['new_name']   ?? null;
    $priv   = $s[$chat]['private']    ?? true;
    $values = $s[$chat]['values']     ?? [];
    $acctSel = $s[$chat]['acct_sel']  ?? 'auto';

    if (!$src || !$name) {
        reset_flow($chat);
        out($chat, "⚠️ <b>Session expired</b>\n\nThe bot restarted and lost this wizard.\n" .
                   "<i>Nothing was created — just run /newhost again.</i>", kb_main());
        return;
    }

    $totalSteps = 3;

    // ── 1 of 3 · copy the template ───────────────────────────
    out($chat, "⏳ <b>Working…</b>  " . bar(1, $totalSteps) . "\n\n🧩 Copying <code>" . esc($src) .
               "</code> → <code>" . esc($name) . "</code>\n<i>This usually takes a few seconds.</i>");

    $desc = "Copy of $src — created by deploy bot";
    [$c, $new, $err] = clone_via_template($src, $GITHUB_OWNER, $name, $priv, $desc);

    if (!$new) {
        say($chat, "⚠️ Template copy didn't work (<i>" . esc($err) . "</i>).\n" .
                   "🔁 <i>Falling back to a file-by-file copy — this takes a bit longer.</i>");
        [$c, $new, $err] = clone_via_copy($src, $GITHUB_OWNER, $name, $priv, $branch, $desc);
    }
    if (!$new) {
        reset_flow($chat);
        say($chat, "❌ <b>Couldn't create the new repo</b>\n\n<code>" . esc($err) . "</code>\n\n" .
                   "<i>Nothing was created. Run /check to test your GitHub token, then try again.</i>", kb_back());
        return;
    }

    $newFull   = $new['full_name'];
    $newBranch = $new['default_branch'] ?? 'main';
    say($chat, "✅ <b>Repository created</b>\n📦 <code>" . esc($newFull) . "</code>\n" .
               "🔗 https://github.com/$newFull");

    // ── 2 of 3 · write the config ────────────────────────────
    say($chat, "⏳ <b>Working…</b>  " . bar(2, $totalSteps) . "\n\n📝 Updating <code>" . esc($CONFIG_PATH) . "</code>");

    $file = wait_for_file($newFull, $newBranch, $CONFIG_PATH);
    if (!$file) {
        reset_flow($chat);
        say($chat, "❌ <b>No " . esc($CONFIG_PATH) . " in the new repo</b>\n\n" .
                   "The repo was created, but that file isn't there.\n" .
                   "<i>Either the template doesn't have it, or CONFIG_PATH points somewhere else. " .
                   "You can fix it by hand here:</i>\nhttps://github.com/$newFull", kb_back());
        return;
    }
    $content = base64_decode(str_replace("\n", '', $file['content']));

    $changed = []; $missing = [];
    foreach ($values as $var => $val) {
        foreach (array_merge([$var], $FIELD_ALIASES[$var] ?? []) as $target) {
            [$content, $ok] = replace_var($content, $target, $val);
            if ($ok) $changed[] = $target; else $missing[] = $target;
        }
    }

    [$c2, $cres] = gh('PUT', "/repos/$newFull/contents/" . rawurlencode($CONFIG_PATH), [
        'message' => "chore: configure $name",
        'content' => base64_encode($content),
        'sha'     => $file['sha'],
        'branch'  => $newBranch,
    ]);
    if ($c2 < 200 || $c2 >= 300) {
        reset_flow($chat);
        say($chat, "❌ <b>Couldn't save the config</b> (HTTP " . esc($c2) . ")\n<code>" .
                   esc(substr(json_encode($cres), 0, 250)) . "</code>\n\n" .
                   "<i>The repo exists — you can edit the file manually and deploy it from /sites.</i>", kb_back());
        return;
    }

    $msg = "✅ <b>Config updated</b>\n";
    if ($changed) $msg .= "   ✏️ Changed: <code>" .
        esc(implode(', ', array_map(fn($m) => '$' . $m, array_unique($changed)))) . "</code>\n";
    if ($missing) $msg .= "   ⚠️ Not found in the file: <code>" .
        esc(implode(', ', array_map(fn($m) => '$' . $m, array_unique($missing)))) . "</code>\n" .
        "   <i>Those variables may be named differently in your template.</i>\n";
    say($chat, $msg);

    // ── 3 of 3 · create the Render service, rotating accounts if needed ──
    say($chat, "⏳ <b>Working…</b>  " . bar(3, $totalSteps) . "\n\n🛠 Creating the Render service");

    if ($acctSel === 'auto') {
        // Round-robin starting point, then fall through the rest in
        // listed order as a safety net if the first pick is full.
        $best = pick_best_account();
        $tryOrder = array_values(array_unique(array_merge([$best], array_keys($RENDER_ACCOUNTS))));
    } else {
        $tryOrder = [(int)$acctSel];
    }

    $sid = null; $usedAcct = null; $lastErr = null; $lastCode = null; $attempts = [];
    foreach ($tryOrder as $acctIdx) {
        $acct = account($acctIdx);
        if (!$acct) continue;

        $svcName = free_service_name(sanitize_service_name($name), $acctIdx);

        [$c3, $created] = rnd('POST', '/services', [
            'type' => 'web_service', 'name' => $svcName, 'ownerId' => $acct['owner_id'],
            'repo' => "https://github.com/$newFull", 'branch' => $newBranch, 'autoDeploy' => 'yes',
            'serviceDetails' => [
                'region' => $RENDER_REGION, 'plan' => 'free', 'runtime' => 'docker',
                'envSpecificDetails' => ['dockerfilePath' => './Dockerfile', 'dockerContext' => '.'],
            ],
        ], $acctIdx);

        if ($c3 >= 200 && $c3 < 300) {
            $sid = $created['service']['id'] ?? ($created['id'] ?? null);
            $usedAcct = $acctIdx;
            break;
        }

        $attempts[] = $acct['name'] . ' (HTTP ' . $c3 . ')';
        $lastErr = $created; $lastCode = $c3;

        // Only silently rotate to the next account when this looks like a
        // plan/service limit — a real error (bad repo, auth, etc) should stop.
        if ($acctSel === 'auto' && looks_like_limit($created) && $acctIdx !== end($tryOrder)) {
            say($chat, "⚠️ <b>" . esc($acct['name']) . "</b> looks full — trying the next account…");
            continue;
        }
        break;
    }

    if (!$sid) {
        reset_flow($chat);
        $triedTxt = $attempts ? "\n\n<i>Tried: " . esc(implode(', ', $attempts)) . "</i>" : '';
        say($chat, "❌ <b>Couldn't create the Render service</b> (HTTP " . esc($lastCode) . ")\n<code>" .
                   esc(substr(json_encode($lastErr), 0, 250)) . "</code>" . $triedTxt . "\n\n" .
                   "💡 <b>If it says the repo wasn't found:</b> on GitHub go to " .
                   "<i>Settings → Applications → Render</i> and switch it to <b>All repositories</b>. " .
                   "Then open /sites and deploy from there.\n\n" .
                   "📦 Your repo is safe: https://github.com/$newFull", kb_back());
        return;
    }

    $s = load_state();
    $s[$chat] = ['step' => 'idle', 'service_id' => $sid, 'service_acct' => $usedAcct];
    save_state($s);
    if ($acctSel === 'auto') remember_auto_account($usedAcct);

    $acctNote = count($RENDER_ACCOUNTS) > 1 ? "🔀 Account: <code>" . esc(account_name($usedAcct)) . "</code>\n" : '';

    say($chat,
        "🎉 <b>All done!</b>\n" . rule() . "\n" .
        "🧩 Template: <code>" . esc($src) . "</code> <i>(untouched)</i>\n" .
        "📦 Repo: <code>" . esc($newFull) . "</code>\n" .
        $acctNote .
        rule() . "\n" .
        "⏱ The first build takes roughly <b>2–5 minutes</b>.\n\n" .
        "<i>Tap Check status to watch it. When it turns ✅ Live, a link to your site appears.</i>",
        [[['text' => '📊 Check status', 'callback_data' => "act:status:$usedAcct:$sid"],
          ['text' => '📜 Logs', 'callback_data' => "act:logs:$usedAcct:$sid"]],
         [['text' => '📦 Open on GitHub', 'url' => "https://github.com/$newFull"]],
         [['text' => '🏠 Main menu', 'callback_data' => 'nav:menu']]]);
}

// ============================================================
//  16. STATUS & LOGS
// ============================================================
function show_status($chat, $sid = null, $acctIdx = null) {
    global $STATUS_INFO, $RENDER_ACCOUNTS;
    $s   = load_state();
    $sid = $sid ?: ($s[$chat]['service_id'] ?? null);
    $acctIdx = $acctIdx !== null ? $acctIdx : ($s[$chat]['service_acct'] ?? 0);
    if (!$sid) {
        out($chat, "🤷 <b>Nothing to show yet</b>\n\nI'm not watching any service right now.\n" .
                   "<i>Open 🗂 My sites and pick one, or start a new deployment.</i>", kb_main());
        return;
    }

    [$cs, $svc]  = rnd('GET', "/services/$sid", null, $acctIdx);
    [, $deploys] = rnd('GET', "/services/$sid/deploys?limit=1", null, $acctIdx);
    if ($cs !== 200) {
        out($chat, "❌ <b>Couldn't reach that service</b> (HTTP " . esc($cs) . ").\n" .
                   "<i>It may have been deleted. Try 🗂 My sites.</i>", kb_back());
        return;
    }

    $d      = $deploys[0]['deploy'] ?? [];
    $status = $d['status'] ?? 'unknown';
    [$emo, $meaning] = $STATUS_INFO[$status] ?? $STATUS_INFO['unknown'];
    $url    = $svc['serviceDetails']['url'] ?? null;
    $susp   = ($svc['suspended'] ?? '') === 'suspended';
    $commit = $d['commit']['message'] ?? null;
    $when   = $d['finishedAt'] ?? ($d['createdAt'] ?? null);
    $acctNote = count($RENDER_ACCOUNTS) > 1 ? "🔀 <i>" . esc(account_name($acctIdx)) . "</i>\n" : '';

    $txt  = "📊 <b>" . esc($svc['name'] ?? $sid) . "</b>\n" . $acctNote . rule() . "\n";
    $txt .= "$emo <b>" . esc(ucfirst(str_replace('_', ' ', $status))) . "</b>\n";
    $txt .= "<i>" . esc($meaning) . "</i>\n\n";
    $txt .= $susp ? "⏸ Service is <b>stopped</b>\n" : "▶️ Service is <b>active</b>\n";
    if ($commit) $txt .= "📝 " . esc(mb_substr(strtok($commit, "\n"), 0, 60)) . "\n";
    if ($when)   $txt .= "🕒 " . esc(date('M j, g:i A', strtotime($when))) . "\n";

    if ($status === 'live' && $url) {
        $txt .= "\n🌐 <b>Your site is live</b>\n" . esc($url) . "\n";
    } elseif (in_array($status, ['build_failed', 'update_failed', 'pre_deploy_failed'], true)) {
        $txt .= "\n💡 <i>Open the logs — the error is usually in the last few lines.</i>\n";
    } elseif (!in_array($status, ['live', 'canceled', 'deactivated'], true)) {
        $txt .= "\n⏳ <i>Still working. Tap Refresh in a minute or so.</i>\n";
    }
    $txt .= "\n<i>Checked at " . date('g:i:s A') . "</i>";

    $rows = [[['text' => '🔄 Refresh', 'callback_data' => "act:status:$acctIdx:$sid"],
              ['text' => '📜 Logs',    'callback_data' => "act:logs:$acctIdx:$sid"]]];
    if ($url) $rows[] = [['text' => '🌐 Open the site', 'url' => $url]];
    $rows[] = [['text' => '⚙️ Manage', 'callback_data' => "svc:$acctIdx:$sid"],
               ['text' => '🏠 Menu',   'callback_data' => 'nav:menu']];
    out($chat, $txt, $rows);
}

function show_logs($chat, $sid = null, $acctIdx = null) {
    $s   = load_state();
    $sid = $sid ?: ($s[$chat]['service_id'] ?? null);
    $acctIdx = $acctIdx !== null ? $acctIdx : ($s[$chat]['service_acct'] ?? 0);
    if (!$sid) {
        out($chat, "🤷 <b>No service selected</b>\n\n<i>Pick one from 🗂 My sites first.</i>", kb_main());
        return;
    }
    $acct = account($acctIdx);
    if (!$acct) { out($chat, "⚠️ That Render account isn't configured anymore.", kb_back()); return; }

    $q = http_build_query([
        'ownerId' => $acct['owner_id'], 'resource' => [$sid],
        'limit' => 40, 'direction' => 'backward',
    ]);
    [$c, $logs] = rnd('GET', "/logs?$q", null, $acctIdx);
    if ($c !== 200) {
        out($chat, "❌ <b>Couldn't fetch the logs</b> (HTTP " . esc($c) . ").\n" .
                   "<i>Render sometimes rate-limits this — wait a moment and retry.</i>",
            [[['text' => '🔄 Try again', 'callback_data' => "act:logs:$acctIdx:$sid"]],
             [['text' => '🏠 Menu', 'callback_data' => 'nav:menu']]]);
        return;
    }

    $lines = [];
    foreach (($logs['logs'] ?? []) as $l) {
        $t = isset($l['timestamp']) ? date('H:i:s', strtotime($l['timestamp'])) . '  ' : '';
        $lines[] = $t . ($l['message'] ?? json_encode($l));
    }
    $lines = array_reverse($lines);                      // newest at the bottom
    $body  = $lines ? implode("\n", array_slice($lines, -40))
                    : "No log lines yet — the build may not have started.";
    if (strlen($body) > 3400) $body = "…\n" . substr($body, -3400);

    out($chat,
        "📜 <b>Recent logs</b>\n<i>Newest lines at the bottom.</i>\n\n" .
        "<pre>" . esc($body) . "</pre>",
        [[['text' => '🔄 Refresh', 'callback_data' => "act:logs:$acctIdx:$sid"],
          ['text' => '📊 Status',  'callback_data' => "act:status:$acctIdx:$sid"]],
         [['text' => '🏠 Menu', 'callback_data' => 'nav:menu']]]);
}

// ============================================================
//  17. SITES & REPOS
// ============================================================
/** $acctSel is an account index, or 'all' to merge every account together. */
function list_services($chat, $page = 0, $acctSel = 0) {
    global $PAGE_SIZE, $RENDER_ACCOUNTS;

    if ($acctSel === 'all') {
        $svcs = render_services_all();
        $c = 200;
    } else {
        [$c, $svcs] = render_services((int)$acctSel);
    }

    if ($c !== 200) {
        out($chat, "❌ <b>Couldn't load your services</b> (HTTP " . esc($c) . ").\n" .
                   "<i>Run /check to test the Render connection.</i>", kb_back());
        return;
    }
    if (!$svcs) {
        out($chat, "📭 <b>No sites yet</b>\n\nNo web services found there.\n" .
                   "<i>Start with a new deployment, or check a different account.</i>",
            [[['text' => '🚀 New deployment', 'callback_data' => 'nav:newhost']],
             [['text' => '🔀 Try another account', 'callback_data' => 'nav:sites']],
             kb_back()[0]]);
        return;
    }

    $total = count($svcs);
    $pages = (int)ceil($total / $PAGE_SIZE);
    $page  = max(0, min($page, $pages - 1));

    $rows = [];
    foreach (array_slice($svcs, $page * $PAGE_SIZE, $PAGE_SIZE) as $svc) {
        $icon = (($svc['suspended'] ?? '') === 'suspended') ? '⏸' : '🟢';
        $acctIdx = $svc['_acct'] ?? (is_numeric($acctSel) ? (int)$acctSel : 0);
        $label = "$icon " . $svc['name'];
        if ($acctSel === 'all' && count($RENDER_ACCOUNTS) > 1) $label .= "  ·  " . account_name($acctIdx);
        $rows[] = [['text' => $label, 'callback_data' => "svc:$acctIdx:" . $svc['id']]];
    }
    $nav = [];
    if ($page > 0)          $nav[] = ['text' => '◀️ Back', 'callback_data' => "svcpg:$acctSel:" . ($page - 1)];
    if ($pages > 1)         $nav[] = ['text' => '📄 ' . ($page + 1) . " / $pages", 'callback_data' => 'noop'];
    if ($page < $pages - 1) $nav[] = ['text' => 'Next ▶️', 'callback_data' => "svcpg:$acctSel:" . ($page + 1)];
    if ($nav) $rows[] = $nav;
    if (count($RENDER_ACCOUNTS) > 1) $rows[] = [['text' => '🔀 Switch account', 'callback_data' => 'nav:sites']];
    $rows[] = [['text' => '🏠 Main menu', 'callback_data' => 'nav:menu']];

    $where = ($acctSel === 'all') ? 'all accounts' : account_name($acctSel);
    out($chat, "🗂 <b>My sites</b>  <i>($total · " . esc($where) . ")</i>\n" . rule() . "\n" .
               "🟢 running   ⏸ stopped\n\n<i>Tap a site to redeploy, restart, stop or delete it.</i>", $rows);
}

/** Repos this bot created, identified by the description it writes. */
function list_clones($chat) {
    out($chat, "⏳ Looking through your repositories…");
    [$c, $repos] = gh('GET', '/user/repos?per_page=100&sort=created&affiliation=owner');
    if ($c !== 200) {
        say($chat, "❌ <b>Couldn't load your repos</b> (HTTP " . esc($c) . ").", kb_back());
        return;
    }

    $lines = [];
    foreach ($repos as $r) {
        if (stripos($r['description'] ?? '', 'deploy bot') === false) continue;
        $lock = ($r['private'] ?? false) ? '🔒' : '🌐';
        $lines[] = "$lock <code>" . esc($r['full_name']) . "</code>";
        if (count($lines) >= 30) break;
    }

    say($chat, $lines
        ? "📦 <b>Repos I created</b>  <i>(" . count($lines) . ")</i>\n" . rule() . "\n" .
          implode("\n", $lines) . "\n\n" .
          "<i>Deleting a Render service does not delete these — remove them on GitHub if you want them gone.</i>"
        : "📭 <b>Nothing here yet</b>\n\nI haven't created any repos so far.\n" .
          "<i>They'll show up here after your first deployment.</i>",
        kb_main());
}

function service_menu($chat, $acctIdx, $sid) {
    [$c, $svc] = rnd('GET', "/services/$sid", null, $acctIdx);
    if ($c !== 200) {
        out($chat, "❌ <b>Service not found</b> (HTTP " . esc($c) . ").\n<i>It may have been deleted.</i>", kb_back());
        return;
    }
    $s = load_state(); $s[$chat]['service_id'] = $sid; $s[$chat]['service_acct'] = $acctIdx; save_state($s);

    global $RENDER_ACCOUNTS;
    $susp = ($svc['suspended'] ?? '') === 'suspended';
    $url  = $svc['serviceDetails']['url'] ?? null;
    $repo = $svc['repo'] ?? null;

    $txt  = "⚙️ <b>" . esc($svc['name'] ?? $sid) . "</b>\n" . rule() . "\n";
    if (count($RENDER_ACCOUNTS) > 1) $txt .= "🔀 Account: <code>" . esc(account_name($acctIdx)) . "</code>\n";
    $txt .= $susp ? "⏸ <b>Stopped</b> — the site is offline\n" : "🟢 <b>Running</b>\n";
    $txt .= "🌿 Branch: <code>" . esc($svc['branch'] ?? '?') . "</code>\n";
    if ($repo) $txt .= "📦 " . esc($repo) . "\n";
    if ($url)  $txt .= "🌐 " . esc($url) . "\n";
    $txt .= rule() . "\n";
    $txt .= "<i>Redeploy = rebuild from the latest commit.\n" .
            "Restart = same build, fresh start.\n" .
            "Stop = take it offline without deleting anything.</i>";

    $rows = [
        [['text' => '📊 Status', 'callback_data' => "act:status:$acctIdx:$sid"],
         ['text' => '📜 Logs',   'callback_data' => "act:logs:$acctIdx:$sid"]],
        [['text' => '🚀 Redeploy', 'callback_data' => "act:redeploy:$acctIdx:$sid"],
         ['text' => '♻️ Restart',  'callback_data' => "act:restart:$acctIdx:$sid"]],
        $susp ? [['text' => '▶️ Start again', 'callback_data' => "act:resume:$acctIdx:$sid"]]
              : [['text' => '⏸ Stop the site', 'callback_data' => "act:suspend:$acctIdx:$sid"]],
        [['text' => '🗑 Delete service', 'callback_data' => "act:delask:$acctIdx:$sid"]],
        [['text' => '↩️ All sites', 'callback_data' => 'nav:sites'],
         ['text' => '🏠 Menu',      'callback_data' => 'nav:menu']],
    ];
    if ($url)  array_splice($rows, 4, 0, [[['text' => '🌐 Open the site', 'url' => $url]]]);
    if ($repo) array_splice($rows, 4, 0, [[['text' => '📦 Open on GitHub', 'url' => $repo]]]);
    out($chat, $txt, $rows);
}

function service_action($chat, $action, $acctIdx, $sid) {
    $s = load_state(); $s[$chat]['service_id'] = $sid; $s[$chat]['service_acct'] = $acctIdx; save_state($s);
    $back = [['text' => '↩️ Back', 'callback_data' => "svc:$acctIdx:$sid"]];

    switch ($action) {
        case 'status': show_status($chat, $sid, $acctIdx); return;
        case 'logs':   show_logs($chat, $sid, $acctIdx);   return;

        case 'redeploy':
            toast('Starting a new deploy…');
            [$c] = rnd('POST', "/services/$sid/deploys", [], $acctIdx);
            out($chat, $c < 300
                ? "🚀 <b>Redeploy started</b>\n\nRender is rebuilding from the latest commit.\n" .
                  "<i>Usually 2–5 minutes.</i>"
                : "❌ <b>Redeploy failed</b> (HTTP " . esc($c) . ").",
                [[['text' => '📊 Watch status', 'callback_data' => "act:status:$acctIdx:$sid"]], $back]);
            return;

        case 'restart':
            toast('Restarting…');
            [$c] = rnd('POST', "/services/$sid/restart", [], $acctIdx);
            out($chat, $c < 300
                ? "♻️ <b>Restarted</b>\n\nSame build, fresh process. Back up in a few seconds."
                : "❌ <b>Restart failed</b> (HTTP " . esc($c) . ").", [$back]);
            return;

        case 'suspend':
            toast('Stopping…');
            [$c] = rnd('POST', "/services/$sid/suspend", [], $acctIdx);
            out($chat, $c < 300
                ? "⏸ <b>Site stopped</b>\n\nIt's offline now, but nothing was deleted — " .
                  "you can start it again any time."
                : "❌ <b>Couldn't stop it</b> (HTTP " . esc($c) . ").", [$back]);
            return;

        case 'resume':
            toast('Starting…');
            [$c] = rnd('POST', "/services/$sid/resume", [], $acctIdx);
            out($chat, $c < 300
                ? "▶️ <b>Starting up</b>\n\nGive it a minute, then check the status."
                : "❌ <b>Couldn't start it</b> (HTTP " . esc($c) . ").",
                [[['text' => '📊 Watch status', 'callback_data' => "act:status:$acctIdx:$sid"]], $back]);
            return;

        case 'delask':
            [, $svc] = rnd('GET', "/services/$sid", null, $acctIdx);
            out($chat, "⚠️ <b>Delete this service?</b>\n" . rule() . "\n" .
                       "🏷 <b>" . esc($svc['name'] ?? $sid) . "</b>\n\n" .
                       "This removes the site from Render permanently. It cannot be undone.\n\n" .
                       "ℹ️ <i>The GitHub repo stays — only the Render service is removed.</i>",
                [[['text' => '🗑 Yes, delete it', 'callback_data' => "act:delyes:$acctIdx:$sid"]],
                 [['text' => '↩️ No, keep it',    'callback_data' => "svc:$acctIdx:$sid"]]]);
            return;

        case 'delyes':
            toast('Deleting…');
            [$c] = rnd('DELETE', "/services/$sid", null, $acctIdx);
            if ($c < 300) {
                $st = load_state();
                if (($st[$chat]['service_id'] ?? '') === $sid) { unset($st[$chat]['service_id']); unset($st[$chat]['service_acct']); }
                save_state($st);
            }
            out($chat, $c < 300
                ? "🗑 <b>Service deleted</b>\n\n<i>The GitHub repo is still there if you need it.</i>"
                : "❌ <b>Delete failed</b> (HTTP " . esc($c) . ").",
                [[['text' => '🗂 My sites', 'callback_data' => 'nav:sites'],
                  ['text' => '🏠 Menu',     'callback_data' => 'nav:menu']]]);
            return;
    }
}

// ============================================================
//  18. WEBHOOK ENTRY POINT
// ============================================================
$update = json_decode(file_get_contents('php://input'), true);
if (!$update) { http_response_code(200); echo 'ok'; exit; }

// Telegram retries when a response is slow; don't run the same update twice.
if (already_seen($update['update_id'] ?? null)) { http_response_code(200); echo 'ok'; exit; }

$chat = null; $text = null; $cb = null; $msg_id = null;
if (isset($update['message'])) {
    $chat   = $update['message']['chat']['id'];
    $text   = trim($update['message']['text'] ?? '');
    $msg_id = $update['message']['message_id'] ?? null;
} elseif (isset($update['callback_query'])) {
    $chat      = $update['callback_query']['message']['chat']['id'];
    $cb        = $update['callback_query']['data'];
    $CB_MSG_ID = $update['callback_query']['message']['message_id'];
    $CB_ID     = $update['callback_query']['id'];
}

if ((string)$chat !== (string)$ADMIN_CHAT_ID) {
    if ($chat) say($chat, "🚫 This is a private bot.");
    http_response_code(200); echo 'ok'; exit;
}

ensure_bot_commands();

// ---------- BUTTON PRESSES ----------
if ($cb !== null) {
    if ($cb === 'noop') { toast(); }

    elseif (strpos($cb, 'nav:') === 0) {
        $w = substr($cb, 4); toast();
        if ($w === 'menu')     show_menu($chat);
        if ($w === 'help')     show_help($chat);
        if ($w === 'check')    show_check($chat);
        if ($w === 'status')   show_status($chat);
        if ($w === 'logs')     show_logs($chat);
        if ($w === 'sites')    ask_account($chat, 'sites');
        if ($w === 'repos')    list_clones($chat);
        if ($w === 'accounts') show_accounts($chat);
        if ($w === 'newhost')  { reset_flow($chat); list_repos($chat, 0); }
    }

    elseif (strpos($cb, 'acctsel:') === 0) {
        toast();
        $p = explode(':', $cb, 3);          // acctsel:<purpose>:<idx|all>
        route_after_account($chat, $p[1], is_numeric($p[2]) ? (int)$p[2] : $p[2]);
    }

    elseif (strpos($cb, 'repopg:') === 0) {
        toast(); $p = explode(':', $cb, 3);
        list_repos($chat, (int)($p[1] ?? 0), $p[2] ?? '');
    }

    elseif ($cb === 'reposearch') {
        toast();
        $s = load_state(); $s[$chat]['step'] = 'repo_search'; save_state($s);
        out($chat, "🔍 <b>Search your repos</b>\n\nType part of a name — for example <code>avea</code>.\n" .
                   "<i>Not case sensitive.</i>",
            [[['text' => '↩️ Show all', 'callback_data' => 'repopg:0:']]]);
    }

    elseif (strpos($cb, 'repo:') === 0) {
        toast();
        $i = (int)substr($cb, 5);
        $s = load_state();
        $picked = $s[$chat]['repo_cache'][$i] ?? null;
        if (!$picked) {
            out($chat, "⚠️ <b>That list expired</b>\n\n<i>Run /newhost to start again.</i>", kb_main());
        } else {
            global $DEFAULT_PRIVATE;
            $s[$chat] = [
                'step' => 'repo_name', 'src_repo' => $picked['full'], 'src_branch' => $picked['branch'],
                'private' => $DEFAULT_PRIVATE, 'acct_sel' => 'auto', 'field_idx' => 0, 'values' => [],
                'service_id' => $s[$chat]['service_id'] ?? null,
                'service_acct' => $s[$chat]['service_acct'] ?? null,
                'repo_cache' => $s[$chat]['repo_cache'],
            ];
            save_state($s);
            ask_repo_name($chat);
        }
    }

    elseif ($cb === 'togpriv') {
        $s = load_state();
        $s[$chat]['private'] = !($s[$chat]['private'] ?? true);
        save_state($s);
        toast($s[$chat]['private'] ? 'Now private' : 'Now public');
        ask_repo_name($chat);
    }

    elseif (strpos($cb, 'setacct:') === 0) {
        $sel = substr($cb, 8);
        $s = load_state();
        $s[$chat]['acct_sel'] = $sel;
        save_state($s);
        toast($sel === 'auto' ? 'Auto selected' : ('Selected ' . account_name($sel)));
        ask_deploy_account($chat);
    }
    elseif ($cb === 'acctdone') { toast(); ask_next_field($chat); }

    elseif ($cb === 'skip')   { skip_field($chat); }
    elseif ($cb === 'redo')   {
        toast('Starting the settings over');
        $s = load_state();
        $s[$chat]['field_idx'] = 0; $s[$chat]['values'] = []; $s[$chat]['step'] = 'collect';
        save_state($s); ask_next_field($chat);
    }
    elseif ($cb === 'deploy') { toast('Creating everything now…'); build_everything($chat); }
    elseif ($cb === 'cancel') {
        toast('Cancelled'); reset_flow($chat);
        out($chat, "❌ <b>Cancelled</b>\n\nNo repo was created and nothing was changed.", kb_main());
    }

    elseif (strpos($cb, 'svcpg:') === 0) {
        toast(); $p = explode(':', $cb, 3);       // svcpg:<acctSel>:<page>
        $acctSel = is_numeric($p[1]) ? (int)$p[1] : $p[1];
        list_services($chat, (int)($p[2] ?? 0), $acctSel);
    }
    elseif (strpos($cb, 'svc:') === 0) {
        toast(); $p = explode(':', $cb, 3);       // svc:<acctIdx>:<sid>
        service_menu($chat, (int)$p[1], $p[2] ?? '');
    }
    elseif (strpos($cb, 'act:') === 0) {
        $p = explode(':', $cb, 4);                // act:<action>:<acctIdx>:<sid>
        service_action($chat, $p[1], (int)($p[2] ?? 0), $p[3] ?? '');
        toast();
    }

    http_response_code(200); echo 'ok'; exit;
}

// ---------- TEXT MESSAGES ----------
$state = load_state();
$step  = $state[$chat]['step'] ?? 'idle';
$cmd   = ($text !== '' && $text !== null) ? strtolower(strtok($text, ' @')) : '';

switch ($cmd) {
    case '/start': case '/menu':
        reset_flow($chat); show_menu($chat);
        http_response_code(200); echo 'ok'; exit;
    case '/help':
        show_help($chat);        http_response_code(200); echo 'ok'; exit;
    case '/check':
        show_check($chat);       http_response_code(200); echo 'ok'; exit;
    case '/accounts':
        show_accounts($chat);    http_response_code(200); echo 'ok'; exit;
    case '/newhost':
        reset_flow($chat); list_repos($chat, 0);
        http_response_code(200); echo 'ok'; exit;
    case '/sites':
        ask_account($chat, 'sites'); http_response_code(200); echo 'ok'; exit;
    case '/repos':
        list_clones($chat);      http_response_code(200); echo 'ok'; exit;
    case '/status':
        show_status($chat);      http_response_code(200); echo 'ok'; exit;
    case '/logs':
        show_logs($chat);        http_response_code(200); echo 'ok'; exit;
    case '/cancel':
        reset_flow($chat);
        say($chat, "❌ <b>Cancelled</b>\n\nNothing was created or changed.", kb_main());
        http_response_code(200); echo 'ok'; exit;
}

if ($step === 'repo_search')     { list_repos($chat, 0, $text); }
elseif ($step === 'repo_name')   { set_repo_name($chat, $text); }
elseif ($step === 'collect')     { take_field($chat, $text, $msg_id); }
elseif ($step === 'review')      {
    say($chat, "☝️ Everything is ready — tap <b>✅ Create &amp; deploy</b> in the message above, " .
               "or send /cancel to back out.");
}
elseif ($step === 'choose_repo') {
    say($chat, "☝️ Tap one of the repos above, or use 🔍 Search to narrow the list.");
}
else {
    say($chat, "🤔 <b>I didn't catch that</b>\n\n<i>Here's what I can do:</i>", kb_main());
}

http_response_code(200);
echo 'ok';
