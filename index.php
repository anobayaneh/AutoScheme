<?php
/**
 * ╔══════════════════════════════════════════════════════════════════════╗
 * ║  TELEGRAM → GITHUB → RENDER DEPLOY BOT   (v4 — English UI)            ║
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
 *  COMMANDS
 *    /start /menu → main menu           /status → latest deploy state
 *    /newhost     → deployment wizard   /logs   → recent log lines
 *    /sites       → manage live sites   /check  → test API connections
 *    /repos       → list bot-made repos /cancel → abort the wizard
 *    /help        → how this works
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
$RENDER_API_KEY  = getenv('RENDER_API_KEY');
$RENDER_OWNER_ID = getenv('RENDER_OWNER_ID');
$RENDER_REGION   = getenv('RENDER_REGION') ?: 'singapore';
$CONFIG_PATH     = getenv('CONFIG_PATH') ?: 'config.php';
$STATE_FILE      = getenv('STATE_FILE') ?: '/tmp/bot_state.json';
$DEFAULT_PRIVATE = getenv('CLONE_PRIVATE') === null ? true : (getenv('CLONE_PRIVATE') !== 'no');

$PAGE_SIZE      = 8;     // buttons per page
$MAX_COPY_FILES = 300;   // ceiling for the fallback copy method

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
    return [
        [['text' => '🚀 New deployment', 'callback_data' => 'nav:newhost']],
        [['text' => '🗂 My sites', 'callback_data' => 'nav:sites'],
         ['text' => '📦 My repos', 'callback_data' => 'nav:repos']],
        [['text' => '📊 Status', 'callback_data' => 'nav:status'],
         ['text' => '📜 Logs',   'callback_data' => 'nav:logs']],
        [['text' => '🩺 Connection check', 'callback_data' => 'nav:check'],
         ['text' => '❓ Help', 'callback_data' => 'nav:help']],
    ];
}
function kb_back() { return [[['text' => '🏠 Main menu', 'callback_data' => 'nav:menu']]]; }

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

function rnd($method, $path, $body = null) {
    global $RENDER_API_KEY;
    $h = ["Authorization: Bearer $RENDER_API_KEY", "Accept: application/json"];
    if ($body !== null) $h[] = "Content-Type: application/json";
    [$c, $r] = http($method, "https://api.render.com/v1$path", $h, $body !== null ? json_encode($body) : null);
    return [$c, json_decode($r, true)];
}

function render_services() {
    global $RENDER_OWNER_ID;
    [$c, $svcs] = rnd('GET', "/services?ownerId=$RENDER_OWNER_ID&limit=100");
    if ($c !== 200 || !is_array($svcs)) return [$c, []];
    $list = [];
    foreach ($svcs as $item) {
        $svc = $item['service'] ?? $item;
        if (($svc['type'] ?? '') !== 'web_service') continue;
        $list[] = $svc;
    }
    return [200, $list];
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
/** Clear the wizard but remember which service we are watching. */
function reset_flow($chat) {
    $s = load_state();
    $keep = $s[$chat]['service_id'] ?? null;
    $seen = $s['_seen'] ?? [];
    $s[$chat] = ['step' => 'idle'];
    if ($keep) $s[$chat]['service_id'] = $keep;
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
/** If "avea-zae" is taken, try "avea-zae-2", "-3", and so on. */
function free_service_name($base) {
    [, $svcs] = render_services();
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
    out($chat,
        "🏠 <b>Deploy Bot</b>\n" . rule() . "\n" .
        "I copy one of your GitHub repos, drop in a fresh <code>config.php</code>, " .
        "and put the copy live on Render.\n\n" .
        "Your original repo is never edited — every client gets their own copy.\n\n" .
        "👇 <i>Pick something below, or type a command.</i>",
        kb_main());
}

function show_help($chat) {
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
        "3️⃣ Answer the config questions one at a time\n" .
        "4️⃣ Review everything, then confirm\n\n" .
        "Nothing is created until you tap <b>Create &amp; deploy</b> in step 4. " .
        "You can back out with ❌ Cancel at any point.\n\n" .
        "<b>Commands</b>\n" .
        "🚀 <code>/newhost</code> — start the wizard\n" .
        "🗂 <code>/sites</code> — redeploy, restart, stop or delete a site\n" .
        "📦 <code>/repos</code> — repos this bot has created\n" .
        "📊 <code>/status</code> — how the latest deploy is doing\n" .
        "📜 <code>/logs</code> — last 40 log lines\n" .
        "🩺 <code>/check</code> — test the GitHub and Render connections\n" .
        "🛑 <code>/cancel</code> — abort the wizard\n" .
        "🏠 <code>/menu</code> — back to the main menu\n\n" .
        "<b>One-time setup to check</b>\n" .
        "On GitHub go to <i>Settings → Applications → Render</i> and give it access to " .
        "<b>All repositories</b>. Otherwise Render can't see the new repos this bot makes.",
        kb_back());
}

/** Quick health check so a failure later is easier to diagnose. */
function show_check($chat) {
    global $GITHUB_OWNER, $RENDER_OWNER_ID, $RENDER_REGION, $CONFIG_PATH, $ADMIN_CHAT_ID;
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

    [$c2, $svcs] = render_services();
    $lines[] = ($c2 === 200)
        ? "✅ <b>Render</b> — connected, " . count($svcs) . " web service(s) found"
        : "❌ <b>Render</b> — request failed (HTTP " . esc($c2) . ")\n     <i>Check RENDER_API_KEY and RENDER_OWNER_ID.</i>";

    $lines[] = "📄 Config file: <code>" . esc($CONFIG_PATH) . "</code>";
    $lines[] = "🌏 Region: <code>" . esc($RENDER_REGION) . "</code>";
    $lines[] = "🔐 Admin chat: <code>" . esc($ADMIN_CHAT_ID) . "</code>";

    say($chat,
        "🩺 <b>Connection check</b>\n" . rule() . "\n" . implode("\n", $lines) . "\n\n" .
        "<i>If both GitHub and Render show ✅, the wizard should work end to end.</i>",
        kb_main());
}

// ============================================================
//  10. STEP 1 — CHOOSE THE TEMPLATE REPO
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

    $head  = "🚀 <b>New deployment</b>   <i>step 1 of 4</i>\n" . bar(1, 4) . "\n" . rule() . "\n";
    $head .= "🧩 <b>Which repo should I copy?</b>\n\n";
    $head .= "This one stays exactly as it is — I only make a copy of it.\n\n";
    $head .= "<i>🧩 already a template · 🔒 private · 📂 public</i>";
    if ($filter !== '') $head .= "\n\n🔍 Filter: <code>" . esc($filter) . "</code>";
    $head .= "\n\n<i>Showing $total repo(s), newest first.</i>";
    out($chat, $head, $rows);
}

// ============================================================
//  11. STEP 2 — NAME THE NEW REPO
// ============================================================
function ask_repo_name($chat) {
    global $DEFAULT_PRIVATE;
    $s    = load_state();
    $src  = $s[$chat]['src_repo'];
    $base = explode('/', $src)[1] ?? $src;
    $priv = $s[$chat]['private'] ?? $DEFAULT_PRIVATE;

    out($chat,
        "🚀 <b>New deployment</b>   <i>step 2 of 4</i>\n" . bar(2, 4) . "\n" . rule() . "\n" .
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
    $s[$chat]['step']      = 'collect';
    $s[$chat]['field_idx'] = 0;
    $s[$chat]['values']    = [];
    save_state($s);

    say($chat, "✅ <b>Name reserved</b>\n📦 <code>" . esc($GITHUB_OWNER) . "/" . esc($name) . "</code>\n\n" .
               "<i>Nothing has been created yet — that happens at the end.</i>");
    ask_next_field($chat);
}

// ============================================================
//  12. STEP 3 — COLLECT CONFIG VALUES
// ============================================================
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

    out($chat,
        "🚀 <b>New deployment</b>   <i>step 3 of 4</i>\n" . bar(3, 4) . "\n" . rule() . "\n" .
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
//  13. STEP 4 — REVIEW BEFORE ANYTHING IS CREATED
// ============================================================
function review($chat) {
    global $CONFIG_FIELDS, $CONFIG_PATH, $RENDER_REGION, $GITHUB_OWNER;
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

    out($chat,
        "🚀 <b>New deployment</b>   <i>step 4 of 4</i>\n" . bar(4, 4) . "\n" . rule() . "\n" .
        "📋 <b>Ready — please review</b>\n\n" .
        "🧩 Copy from: <code>" . esc($s[$chat]['src_repo']) . "</code>\n" .
        "         ↓\n" .
        "📦 New repo: <code>" . esc($GITHUB_OWNER) . "/" . esc($s[$chat]['new_name']) . "</code>\n" .
        "👁 Visibility: " . (($s[$chat]['private'] ?? true) ? '🔒 private' : '🌐 public') . "\n" .
        "🏷 Render service: <code>" . esc($svcName) . "</code>\n" .
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
//  14. BUILD: CLONE → CONFIG → DEPLOY
// ============================================================
function build_everything($chat) {
    global $GITHUB_OWNER, $CONFIG_PATH, $RENDER_OWNER_ID, $RENDER_REGION, $FIELD_ALIASES;

    $s      = load_state();
    $src    = $s[$chat]['src_repo']   ?? null;
    $branch = $s[$chat]['src_branch'] ?? null;
    $name   = $s[$chat]['new_name']   ?? null;
    $priv   = $s[$chat]['private']    ?? true;
    $values = $s[$chat]['values']     ?? [];

    if (!$src || !$name) {
        reset_flow($chat);
        out($chat, "⚠️ <b>Session expired</b>\n\nThe bot restarted and lost this wizard.\n" .
                   "<i>Nothing was created — just run /newhost again.</i>", kb_main());
        return;
    }

    // ── 1 of 3 · copy the template ───────────────────────────
    out($chat, "⏳ <b>Working…</b>  " . bar(1, 3) . "\n\n🧩 Copying <code>" . esc($src) .
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
    say($chat, "⏳ <b>Working…</b>  " . bar(2, 3) . "\n\n📝 Updating <code>" . esc($CONFIG_PATH) . "</code>");

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

    // ── 3 of 3 · create the Render service ───────────────────
    say($chat, "⏳ <b>Working…</b>  " . bar(3, 3) . "\n\n🛠 Creating the Render service");
    $svcName = free_service_name(sanitize_service_name($name));

    [$c3, $created] = rnd('POST', '/services', [
        'type' => 'web_service', 'name' => $svcName, 'ownerId' => $RENDER_OWNER_ID,
        'repo' => "https://github.com/$newFull", 'branch' => $newBranch, 'autoDeploy' => 'yes',
        'serviceDetails' => [
            'region' => $RENDER_REGION, 'plan' => 'free', 'runtime' => 'docker',
            'envSpecificDetails' => ['dockerfilePath' => './Dockerfile', 'dockerContext' => '.'],
        ],
    ]);
    if ($c3 < 200 || $c3 >= 300) {
        reset_flow($chat);
        say($chat, "❌ <b>Couldn't create the Render service</b> (HTTP " . esc($c3) . ")\n<code>" .
                   esc(substr(json_encode($created), 0, 250)) . "</code>\n\n" .
                   "💡 <b>If it says the repo wasn't found:</b> on GitHub go to " .
                   "<i>Settings → Applications → Render</i> and switch it to <b>All repositories</b>. " .
                   "Then open /sites and deploy from there.\n\n" .
                   "📦 Your repo is safe: https://github.com/$newFull", kb_back());
        return;
    }
    $sid = $created['service']['id'] ?? ($created['id'] ?? null);

    $s = load_state();
    $s[$chat] = ['step' => 'idle', 'service_id' => $sid];
    save_state($s);

    say($chat,
        "🎉 <b>All done!</b>\n" . rule() . "\n" .
        "🧩 Template: <code>" . esc($src) . "</code> <i>(untouched)</i>\n" .
        "📦 Repo: <code>" . esc($newFull) . "</code>\n" .
        "🏷 Service: <code>" . esc($svcName) . "</code>\n" .
        rule() . "\n" .
        "⏱ The first build takes roughly <b>2–5 minutes</b>.\n\n" .
        "<i>Tap Check status to watch it. When it turns ✅ Live, a link to your site appears.</i>",
        [[['text' => '📊 Check status', 'callback_data' => "act:status:$sid"],
          ['text' => '📜 Logs', 'callback_data' => "act:logs:$sid"]],
         [['text' => '📦 Open on GitHub', 'url' => "https://github.com/$newFull"]],
         [['text' => '🏠 Main menu', 'callback_data' => 'nav:menu']]]);
}

// ============================================================
//  15. STATUS & LOGS
// ============================================================
function show_status($chat, $sid = null) {
    global $STATUS_INFO;
    $s   = load_state();
    $sid = $sid ?: ($s[$chat]['service_id'] ?? null);
    if (!$sid) {
        out($chat, "🤷 <b>Nothing to show yet</b>\n\nI'm not watching any service right now.\n" .
                   "<i>Open 🗂 My sites and pick one, or start a new deployment.</i>", kb_main());
        return;
    }

    [$cs, $svc]  = rnd('GET', "/services/$sid");
    [, $deploys] = rnd('GET', "/services/$sid/deploys?limit=1");
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

    $txt  = "📊 <b>" . esc($svc['name'] ?? $sid) . "</b>\n" . rule() . "\n";
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

    $rows = [[['text' => '🔄 Refresh', 'callback_data' => "act:status:$sid"],
              ['text' => '📜 Logs',    'callback_data' => "act:logs:$sid"]]];
    if ($url) $rows[] = [['text' => '🌐 Open the site', 'url' => $url]];
    $rows[] = [['text' => '⚙️ Manage', 'callback_data' => "svc:$sid"],
               ['text' => '🏠 Menu',   'callback_data' => 'nav:menu']];
    out($chat, $txt, $rows);
}

function show_logs($chat, $sid = null) {
    global $RENDER_OWNER_ID;
    $s   = load_state();
    $sid = $sid ?: ($s[$chat]['service_id'] ?? null);
    if (!$sid) {
        out($chat, "🤷 <b>No service selected</b>\n\n<i>Pick one from 🗂 My sites first.</i>", kb_main());
        return;
    }

    $q = http_build_query([
        'ownerId' => $RENDER_OWNER_ID, 'resource' => [$sid],
        'limit' => 40, 'direction' => 'backward',
    ]);
    [$c, $logs] = rnd('GET', "/logs?$q");
    if ($c !== 200) {
        out($chat, "❌ <b>Couldn't fetch the logs</b> (HTTP " . esc($c) . ").\n" .
                   "<i>Render sometimes rate-limits this — wait a moment and retry.</i>",
            [[['text' => '🔄 Try again', 'callback_data' => "act:logs:$sid"]],
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
        [[['text' => '🔄 Refresh', 'callback_data' => "act:logs:$sid"],
          ['text' => '📊 Status',  'callback_data' => "act:status:$sid"]],
         [['text' => '🏠 Menu', 'callback_data' => 'nav:menu']]]);
}

// ============================================================
//  16. SITES & REPOS
// ============================================================
function list_services($chat, $page = 0) {
    global $PAGE_SIZE;
    [$c, $svcs] = render_services();
    if ($c !== 200) {
        out($chat, "❌ <b>Couldn't load your services</b> (HTTP " . esc($c) . ").\n" .
                   "<i>Run /check to test the Render connection.</i>", kb_back());
        return;
    }
    if (!$svcs) {
        out($chat, "📭 <b>No sites yet</b>\n\nYou don't have any web services in this Render workspace.\n" .
                   "<i>Start with a new deployment.</i>", kb_main());
        return;
    }

    $total = count($svcs);
    $pages = (int)ceil($total / $PAGE_SIZE);
    $page  = max(0, min($page, $pages - 1));

    $rows = [];
    foreach (array_slice($svcs, $page * $PAGE_SIZE, $PAGE_SIZE) as $svc) {
        $icon = (($svc['suspended'] ?? '') === 'suspended') ? '⏸' : '🟢';
        $rows[] = [['text' => "$icon " . $svc['name'], 'callback_data' => 'svc:' . $svc['id']]];
    }
    $nav = [];
    if ($page > 0)          $nav[] = ['text' => '◀️ Back', 'callback_data' => 'svcpg:' . ($page - 1)];
    if ($pages > 1)         $nav[] = ['text' => '📄 ' . ($page + 1) . " / $pages", 'callback_data' => 'noop'];
    if ($page < $pages - 1) $nav[] = ['text' => 'Next ▶️', 'callback_data' => 'svcpg:' . ($page + 1)];
    if ($nav) $rows[] = $nav;
    $rows[] = [['text' => '🏠 Main menu', 'callback_data' => 'nav:menu']];

    out($chat, "🗂 <b>My sites</b>  <i>($total)</i>\n" . rule() . "\n" .
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

function service_menu($chat, $sid) {
    [$c, $svc] = rnd('GET', "/services/$sid");
    if ($c !== 200) {
        out($chat, "❌ <b>Service not found</b> (HTTP " . esc($c) . ").\n<i>It may have been deleted.</i>", kb_back());
        return;
    }
    $s = load_state(); $s[$chat]['service_id'] = $sid; save_state($s);

    $susp = ($svc['suspended'] ?? '') === 'suspended';
    $url  = $svc['serviceDetails']['url'] ?? null;
    $repo = $svc['repo'] ?? null;

    $txt  = "⚙️ <b>" . esc($svc['name'] ?? $sid) . "</b>\n" . rule() . "\n";
    $txt .= $susp ? "⏸ <b>Stopped</b> — the site is offline\n" : "🟢 <b>Running</b>\n";
    $txt .= "🌿 Branch: <code>" . esc($svc['branch'] ?? '?') . "</code>\n";
    if ($repo) $txt .= "📦 " . esc($repo) . "\n";
    if ($url)  $txt .= "🌐 " . esc($url) . "\n";
    $txt .= rule() . "\n";
    $txt .= "<i>Redeploy = rebuild from the latest commit.\n" .
            "Restart = same build, fresh start.\n" .
            "Stop = take it offline without deleting anything.</i>";

    $rows = [
        [['text' => '📊 Status', 'callback_data' => "act:status:$sid"],
         ['text' => '📜 Logs',   'callback_data' => "act:logs:$sid"]],
        [['text' => '🚀 Redeploy', 'callback_data' => "act:redeploy:$sid"],
         ['text' => '♻️ Restart',  'callback_data' => "act:restart:$sid"]],
        $susp ? [['text' => '▶️ Start again', 'callback_data' => "act:resume:$sid"]]
              : [['text' => '⏸ Stop the site', 'callback_data' => "act:suspend:$sid"]],
        [['text' => '🗑 Delete service', 'callback_data' => "act:delask:$sid"]],
        [['text' => '↩️ All sites', 'callback_data' => 'svcpg:0'],
         ['text' => '🏠 Menu',      'callback_data' => 'nav:menu']],
    ];
    if ($url)  array_splice($rows, 4, 0, [[['text' => '🌐 Open the site', 'url' => $url]]]);
    if ($repo) array_splice($rows, 4, 0, [[['text' => '📦 Open on GitHub', 'url' => $repo]]]);
    out($chat, $txt, $rows);
}

function service_action($chat, $action, $sid) {
    $s = load_state(); $s[$chat]['service_id'] = $sid; save_state($s);
    $back = [['text' => '↩️ Back', 'callback_data' => "svc:$sid"]];

    switch ($action) {
        case 'status': show_status($chat, $sid); return;
        case 'logs':   show_logs($chat, $sid);   return;

        case 'redeploy':
            toast('Starting a new deploy…');
            [$c] = rnd('POST', "/services/$sid/deploys", []);
            out($chat, $c < 300
                ? "🚀 <b>Redeploy started</b>\n\nRender is rebuilding from the latest commit.\n" .
                  "<i>Usually 2–5 minutes.</i>"
                : "❌ <b>Redeploy failed</b> (HTTP " . esc($c) . ").",
                [[['text' => '📊 Watch status', 'callback_data' => "act:status:$sid"]], $back]);
            return;

        case 'restart':
            toast('Restarting…');
            [$c] = rnd('POST', "/services/$sid/restart", []);
            out($chat, $c < 300
                ? "♻️ <b>Restarted</b>\n\nSame build, fresh process. Back up in a few seconds."
                : "❌ <b>Restart failed</b> (HTTP " . esc($c) . ").", [$back]);
            return;

        case 'suspend':
            toast('Stopping…');
            [$c] = rnd('POST', "/services/$sid/suspend", []);
            out($chat, $c < 300
                ? "⏸ <b>Site stopped</b>\n\nIt's offline now, but nothing was deleted — " .
                  "you can start it again any time."
                : "❌ <b>Couldn't stop it</b> (HTTP " . esc($c) . ").", [$back]);
            return;

        case 'resume':
            toast('Starting…');
            [$c] = rnd('POST', "/services/$sid/resume", []);
            out($chat, $c < 300
                ? "▶️ <b>Starting up</b>\n\nGive it a minute, then check the status."
                : "❌ <b>Couldn't start it</b> (HTTP " . esc($c) . ").",
                [[['text' => '📊 Watch status', 'callback_data' => "act:status:$sid"]], $back]);
            return;

        case 'delask':
            [, $svc] = rnd('GET', "/services/$sid");
            out($chat, "⚠️ <b>Delete this service?</b>\n" . rule() . "\n" .
                       "🏷 <b>" . esc($svc['name'] ?? $sid) . "</b>\n\n" .
                       "This removes the site from Render permanently. It cannot be undone.\n\n" .
                       "ℹ️ <i>The GitHub repo stays — only the Render service is removed.</i>",
                [[['text' => '🗑 Yes, delete it', 'callback_data' => "act:delyes:$sid"]],
                 [['text' => '↩️ No, keep it',    'callback_data' => "svc:$sid"]]]);
            return;

        case 'delyes':
            toast('Deleting…');
            [$c] = rnd('DELETE', "/services/$sid");
            if ($c < 300) {
                $st = load_state();
                if (($st[$chat]['service_id'] ?? '') === $sid) unset($st[$chat]['service_id']);
                save_state($st);
            }
            out($chat, $c < 300
                ? "🗑 <b>Service deleted</b>\n\n<i>The GitHub repo is still there if you need it.</i>"
                : "❌ <b>Delete failed</b> (HTTP " . esc($c) . ").",
                [[['text' => '🗂 My sites', 'callback_data' => 'svcpg:0'],
                  ['text' => '🏠 Menu',     'callback_data' => 'nav:menu']]]);
            return;
    }
}

// ============================================================
//  17. WEBHOOK ENTRY POINT
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

// ---------- BUTTON PRESSES ----------
if ($cb !== null) {
    if ($cb === 'noop') { toast(); }

    elseif (strpos($cb, 'nav:') === 0) {
        $w = substr($cb, 4); toast();
        if ($w === 'menu')    show_menu($chat);
        if ($w === 'help')    show_help($chat);
        if ($w === 'check')   show_check($chat);
        if ($w === 'status')  show_status($chat);
        if ($w === 'logs')    show_logs($chat);
        if ($w === 'sites')   list_services($chat, 0);
        if ($w === 'repos')   list_clones($chat);
        if ($w === 'newhost') { reset_flow($chat); list_repos($chat, 0); }
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
            $s[$chat] = [
                'step' => 'repo_name', 'src_repo' => $picked['full'], 'src_branch' => $picked['branch'],
                'private' => $DEFAULT_PRIVATE, 'field_idx' => 0, 'values' => [],
                'service_id' => $s[$chat]['service_id'] ?? null,
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

    elseif (strpos($cb, 'svcpg:') === 0) { toast(); list_services($chat, (int)substr($cb, 6)); }
    elseif (strpos($cb, 'svc:') === 0)   { toast(); service_menu($chat, substr($cb, 4)); }
    elseif (strpos($cb, 'act:') === 0) {
        $p = explode(':', $cb, 3);
        service_action($chat, $p[1], $p[2] ?? '');
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
    case '/newhost':
        reset_flow($chat); list_repos($chat, 0);
        http_response_code(200); echo 'ok'; exit;
    case '/sites':
        list_services($chat, 0); http_response_code(200); echo 'ok'; exit;
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
