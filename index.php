<?php
/**
 * Telegram -> GitHub -> Render deploy bot  (single-admin, webhook mode)
 *
 * FLOW:
 *   /newhost  ->  lists your GitHub repos as buttons
 *   pick repo ->  bot asks for each config.php value one by one (type "skip" to keep old)
 *   done      ->  bot commits the new config.php to GitHub, then creates/redeploys on Render
 *   /status   ->  shows current deploy status; sends the live URL once it's "live"
 *   /logs     ->  shows the latest log lines from Render
 *
 * All secrets come from Render ENVIRONMENT VARIABLES. Never hardcode them here.
 */

// ============================================================
//  CONFIG  (set these in Render -> your service -> Environment)
// ============================================================
$TELEGRAM_TOKEN  = getenv('TELEGRAM_TOKEN');   // the CONTROL bot's token (the one you chat with)
$ADMIN_CHAT_ID   = getenv('ADMIN_CHAT_ID');    // your own Telegram chat id (only you may use the bot)
$GITHUB_TOKEN    = getenv('GITHUB_TOKEN');     // GitHub PAT with "repo" scope
$GITHUB_OWNER    = getenv('GITHUB_OWNER');     // your github username (repo owner)
$RENDER_API_KEY  = getenv('RENDER_API_KEY');   // Render API key
$RENDER_OWNER_ID = getenv('RENDER_OWNER_ID');  // Render workspace id (Settings page)
$RENDER_REGION   = getenv('RENDER_REGION') ?: 'singapore';
$CONFIG_PATH     = getenv('CONFIG_PATH') ?: 'config.php';   // path to config file inside the repo
$STATE_FILE      = getenv('STATE_FILE') ?: '/tmp/bot_state.json';

// The config.php variables the bot will ask you about, in order.
// Add / remove lines here to match your own config file.
// Required fields are asked first; the Discord one is optional — type "skip" to keep the old value.
$CONFIG_FIELDS = [
    'telegram_bot_token'  => 'Telegram bot token (required)',
    'telegram_chat_id'    => 'Telegram chat ID (required)',
    'discord_webhook_url' => 'Discord webhook URL (optional — type skip to keep)',
];

// When you answer one field, also copy that same value into these other
// config variables. Here: the "chat ID" answer fills BOTH telegram_chat_id
// and telegram_forward_chat_id, so they always match — but only ONE question.
$FIELD_ALIASES = [
    'telegram_chat_id' => ['telegram_forward_chat_id'],
];

// ============================================================
//  LOW-LEVEL HTTP
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
//  TELEGRAM
// ============================================================
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

// ============================================================
//  GITHUB  &  RENDER  WRAPPERS
// ============================================================
function gh($method, $path, $body = null) {
    global $GITHUB_TOKEN;
    $headers = [
        "Authorization: Bearer $GITHUB_TOKEN",
        "User-Agent: render-deploy-bot",
        "Accept: application/vnd.github+json",
    ];
    if ($body !== null) $headers[] = "Content-Type: application/json";
    [$code, $res] = http($method, "https://api.github.com$path", $headers, $body ? json_encode($body) : null);
    return [$code, json_decode($res, true)];
}
function rnd($method, $path, $body = null) {
    global $RENDER_API_KEY;
    $headers = ["Authorization: Bearer $RENDER_API_KEY", "Accept: application/json"];
    if ($body !== null) $headers[] = "Content-Type: application/json";
    [$code, $res] = http($method, "https://api.render.com/v1$path", $headers, $body ? json_encode($body) : null);
    return [$code, json_decode($res, true)];
}

// ============================================================
//  STATE  (simple JSON file — see notes about durability)
// ============================================================
function load_state() {
    global $STATE_FILE;
    return file_exists($STATE_FILE) ? (json_decode(file_get_contents($STATE_FILE), true) ?: []) : [];
}
function save_state($s) {
    global $STATE_FILE;
    file_put_contents($STATE_FILE, json_encode($s));
}

// Replace `$var = "...";` (or single-quoted) inside a PHP config file.
function replace_var($content, $var, $value) {
    $pattern = '/(\$' . preg_quote($var, '/') . '\s*=\s*)(["\']).*?\2(\s*;)/s';
    $repl    = '${1}"' . addslashes($value) . '"${3}';
    $out     = preg_replace($pattern, $repl, $content, 1);
    return $out === null ? $content : $out;
}

// ============================================================
//  ACTIONS
// ============================================================
function list_repos($chat) {
    [$code, $repos] = gh('GET', '/user/repos?per_page=100&sort=updated&affiliation=owner');
    if ($code !== 200 || !is_array($repos)) { say($chat, "❌ Hindi makuha ang repos (HTTP $code)."); return; }

    $state = load_state();
    $state[$chat] = ['step' => 'choose_repo', 'repos' => []];
    $rows = [];
    foreach ($repos as $i => $r) {
        $state[$chat]['repos'][$i] = ['full' => $r['full_name'], 'branch' => $r['default_branch']];
        $rows[] = [['text' => $r['full_name'], 'callback_data' => "repo:$i"]];
    }
    save_state($state);
    say($chat, "Pumili ng repo na iho-host:", $rows);
}

function ask_next_field($chat) {
    global $CONFIG_FIELDS;
    $state = load_state();
    $keys  = array_keys($CONFIG_FIELDS);
    $idx   = $state[$chat]['field_idx'];

    if ($idx >= count($keys)) { commit_and_deploy($chat); return; }

    $key = $keys[$idx];
    say($chat, "✏️ <b>{$CONFIG_FIELDS[$key]}</b>\nI-type ang bagong value, o <code>skip</code> para panatilihin ang luma.");
}

function commit_and_deploy($chat) {
    global $GITHUB_OWNER, $CONFIG_PATH, $RENDER_OWNER_ID, $RENDER_REGION;
    $state  = load_state();
    $repo   = $state[$chat]['repo'];       // "owner/name"
    $branch = $state[$chat]['branch'];
    $values = $state[$chat]['values'];     // [var => newvalue] (only the non-skipped ones)

    // 1) fetch current config.php (need its sha to update)
    [$c, $file] = gh('GET', "/repos/$repo/contents/" . rawurlencode($CONFIG_PATH) . "?ref=$branch");
    if ($c !== 200 || empty($file['content'])) {
        say($chat, "❌ Hindi mahanap ang $CONFIG_PATH sa $repo (HTTP $c)."); return;
    }
    $content = base64_decode(str_replace("\n", "", $file['content']));

    // 2) apply replacements (plus any aliased vars that should share the same value)
    global $FIELD_ALIASES;
    foreach ($values as $var => $val) {
        $content = replace_var($content, $var, $val);
        foreach (($FIELD_ALIASES[$var] ?? []) as $aliasVar) {
            $content = replace_var($content, $aliasVar, $val);
        }
    }

    // 3) commit the updated file
    [$c2] = gh('PUT', "/repos/$repo/contents/" . rawurlencode($CONFIG_PATH), [
        'message' => 'chore: update config via bot',
        'content' => base64_encode($content),
        'sha'     => $file['sha'],
        'branch'  => $branch,
    ]);
    if ($c2 < 200 || $c2 >= 300) { say($chat, "❌ Nabigo ang commit sa config.php (HTTP $c2)."); return; }
    say($chat, "✅ Na-update ang <code>$CONFIG_PATH</code>.");

    // 4) find existing Render service for this repo, else create a new one
    $repoUrl = "https://github.com/$repo";
    [, $svcs] = rnd('GET', "/services?ownerId=$RENDER_OWNER_ID&limit=100");
    $serviceId = null;
    if (is_array($svcs)) {
        foreach ($svcs as $item) {
            $svc = $item['service'] ?? $item;
            if (($svc['repo'] ?? '') === $repoUrl) { $serviceId = $svc['id']; break; }
        }
    }

    if ($serviceId) {
        // already exists -> just redeploy (the commit above may already have triggered autoDeploy,
        // but we force one to be sure)
        rnd('POST', "/services/$serviceId/deploys", []);
        say($chat, "🔁 Nag-e-redeploy ang existing service...");
    } else {
        // brand new -> create it (this also starts the first deploy automatically)
        $name = 'bot-' . strtolower(preg_replace('/[^a-z0-9]+/i', '-', explode('/', $repo)[1]));
        [$c3, $created] = rnd('POST', '/services', [
            'type'       => 'web_service',
            'name'       => $name,
            'ownerId'    => $RENDER_OWNER_ID,
            'repo'       => $repoUrl,
            'branch'     => $branch,
            'autoDeploy' => 'yes',
            'serviceDetails' => [
                'region'  => $RENDER_REGION,
                'plan'    => 'free',
                'runtime' => 'docker',                 // uses your Dockerfile — no build/start command needed
                'envSpecificDetails' => [
                    'dockerfilePath' => './Dockerfile',
                    'dockerContext'  => '.',
                ],
            ],
        ]);
        if ($c3 < 200 || $c3 >= 300) { say($chat, "❌ Nabigo ang create service (HTTP $c3): " . json_encode($created)); return; }
        $serviceId = $created['service']['id'] ?? ($created['id'] ?? null);
        say($chat, "🚀 Nagawa ang service, nagde-deploy na...");
    }

    // 5) remember which service we're watching
    $state[$chat] = ['step' => 'idle', 'service_id' => $serviceId];
    save_state($state);

    say($chat, "Pindutin ang <code>/status</code> para makita ang progreso, o <code>/logs</code> para sa logs.",
        [[['text' => '🔄 Status', 'callback_data' => 'status'], ['text' => '📜 Logs', 'callback_data' => 'logs']]]);
}

function show_status($chat) {
    $state = load_state();
    $sid   = $state[$chat]['service_id'] ?? null;
    if (!$sid) { say($chat, "Walang aktibong deploy. Mag-<code>/newhost</code> muna."); return; }

    [, $svc] = rnd('GET', "/services/$sid");
    [, $deploys] = rnd('GET', "/services/$sid/deploys?limit=1");
    $status = $deploys[0]['deploy']['status'] ?? 'unknown';

    say($chat, "📦 Deploy status: <b>$status</b>");

    if ($status === 'live') {
        $url = $svc['serviceDetails']['url'] ?? null;
        if ($url) say($chat, "✅ LIVE na!\n$url");
    }
}

function show_logs($chat) {
    global $RENDER_OWNER_ID;
    $state = load_state();
    $sid   = $state[$chat]['service_id'] ?? null;
    if (!$sid) { say($chat, "Walang service na binabantayan."); return; }

    // NOTE: verify exact param names at https://api-docs.render.com/reference/list-logs
    $q = http_build_query([
        'ownerId'   => $RENDER_OWNER_ID,
        'resource'  => $sid,
        'limit'     => 30,
        'direction' => 'backward',
    ]);
    [$c, $logs] = rnd('GET', "/logs?$q");
    if ($c !== 200) { say($chat, "❌ Hindi makuha ang logs (HTTP $c)."); return; }

    $lines = [];
    foreach (($logs['logs'] ?? []) as $l) $lines[] = $l['message'] ?? json_encode($l);
    $text = $lines ? implode("\n", array_slice($lines, -30)) : "(walang logs pa)";
    if (strlen($text) > 3800) $text = substr($text, -3800);
    say($chat, "<pre>" . htmlspecialchars($text) . "</pre>");
}

// ============================================================
//  WEBHOOK ENTRYPOINT
// ============================================================
$update = json_decode(file_get_contents('php://input'), true);
if (!$update) { http_response_code(200); echo 'ok'; exit; }

// --- figure out who is talking + guard admin only ---
$chat = null; $text = null; $cbData = null;
if (isset($update['message'])) {
    $chat = $update['message']['chat']['id'];
    $text = trim($update['message']['text'] ?? '');
} elseif (isset($update['callback_query'])) {
    $chat   = $update['callback_query']['message']['chat']['id'];
    $cbData = $update['callback_query']['data'];
    tg('answerCallbackQuery', ['callback_query_id' => $update['callback_query']['id']]);
}

if ((string)$chat !== (string)$ADMIN_CHAT_ID) { http_response_code(200); echo 'ok'; exit; }

// --- callback buttons ---
if ($cbData !== null) {
    if ($cbData === 'status') { show_status($chat); }
    elseif ($cbData === 'logs') { show_logs($chat); }
    elseif (strpos($cbData, 'repo:') === 0) {
        $i = (int)substr($cbData, 5);
        $state = load_state();
        $picked = $state[$chat]['repos'][$i] ?? null;
        if ($picked) {
            $state[$chat] = [
                'step'      => 'collect',
                'repo'      => $picked['full'],
                'branch'    => $picked['branch'],
                'field_idx' => 0,
                'values'    => [],
            ];
            save_state($state);
            say($chat, "Napili: <b>{$picked['full']}</b> (branch: {$picked['branch']})");
            ask_next_field($chat);
        }
    }
    http_response_code(200); echo 'ok'; exit;
}

// --- text messages ---
$state = load_state();
$step  = $state[$chat]['step'] ?? 'idle';

if ($text === '/start') {
    say($chat, "Kumusta! Gamitin ang <code>/newhost</code> para mag-deploy ng bagong host.");
} elseif ($text === '/newhost') {
    list_repos($chat);
} elseif ($text === '/status') {
    show_status($chat);
} elseif ($text === '/logs') {
    show_logs($chat);
} elseif ($step === 'collect') {
    // we're collecting config.php values
    global $CONFIG_FIELDS;
    $keys = array_keys($CONFIG_FIELDS);
    $key  = $keys[$state[$chat]['field_idx']];
    if (strtolower($text) !== 'skip' && $text !== '') {
        $state[$chat]['values'][$key] = $text;
    }
    $state[$chat]['field_idx']++;
    save_state($state);
    ask_next_field($chat);
} else {
    say($chat, "Gamitin ang <code>/newhost</code> para magsimula.");
}

http_response_code(200);
echo 'ok';
