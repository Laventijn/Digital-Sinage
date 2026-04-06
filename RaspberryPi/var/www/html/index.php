<?php
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');
session_start();

/* =========================================================
   Basis instellingen
   ========================================================= */
$CONFIG_FILE        = '/etc/default/kiosk.conf';
$PRESETS_FILE       = '/etc/default/kiosk-presets.json';
$RESTART_AFTER_SAVE = true;
$AUTH_ENABLED       = true;
$AUTH_CONFIG_FILE   = '/etc/default/kiosk-dashboard-auth.conf';
$AUTH_DEFAULT_USER  = 'beheer';
$AUTH_DEFAULT_PASS  = 'Kiosk123!';
$AUTH_MAX_ATTEMPTS  = 5;
$AUTH_LOCK_SECONDS  = 30;

$CMD_APPLY_RUNTIME  = 'sudo /usr/local/bin/kiosk-apply-runtime.py --from-config --reason dashboard-save';
$CMD_RESTART_KIOSK  = 'sudo systemctl restart kiosk.service';
$CMD_SEQUENCE_ONCE  = 'sudo /usr/local/bin/kiosk-sequence-watcher.py --once';
$CMD_REBOOT_PI      = 'sudo /bin/systemctl reboot';
$CMD_REFRESH_ONLY   = 'sudo -u pi /home/pi/refresh_once.sh';
$CMD_SSH_START      = 'sudo systemctl start ssh';
$CMD_SSH_STOP       = 'sudo systemctl stop ssh';
$CMD_CLEAR_CACHE    = 'sudo /usr/bin/find /home/pi/.cache/chromium -mindepth 1 -delete';

/* =========================================================
   Helpers
   ========================================================= */
function h(?string $s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function sh(string $cmd): string {
    return trim((string)shell_exec($cmd));
}

function runCommandWithStatus(string $cmd): array {
    if (!function_exists('exec')) {
        return [
            'exit_code' => 127,
            'output' => 'PHP exec() is niet beschikbaar.',
        ];
    }

    $output = [];
    $exitCode = 0;
    exec($cmd . ' 2>&1', $output, $exitCode);

    return [
        'exit_code' => (int)$exitCode,
        'output' => trim(implode(PHP_EOL, $output)),
    ];
}

function applyRuntimeAfterConfigSave(
    string $applyCommand,
    string $restartCommand,
    bool $runtimeAllowed = true,
    ?string $deferredCommand = null
): array {
    if (!$runtimeAllowed) {
        $deferredResult = null;
        if ($deferredCommand !== null && trim($deferredCommand) !== '') {
            $deferredResult = runCommandWithStatus($deferredCommand);
        }

        kioskLog('dashboard', 'Runtime apply overgeslagen buiten tijdschema.', [
            'command' => $applyCommand,
            'deferred_command' => (string)$deferredCommand,
            'deferred_exit_code' => is_array($deferredResult) ? (int)($deferredResult['exit_code'] ?? 0) : 'n/a',
            'deferred_output' => is_array($deferredResult) ? (string)($deferredResult['output'] ?? '') : '',
        ]);

        if (is_array($deferredResult) && (int)($deferredResult['exit_code'] ?? 1) !== 0) {
            return [
                'ok' => false,
                'strategy' => 'deferred_failed',
                'apply_result' => null,
                'restart_result' => $deferredResult,
            ];
        }

        return [
            'ok' => true,
            'strategy' => 'deferred',
            'apply_result' => null,
            'restart_result' => $deferredResult,
        ];
    }

    $applyResult = runCommandWithStatus($applyCommand);
    if ((int)$applyResult['exit_code'] === 0) {
        kioskLog('dashboard', 'Runtime apply gelukt na config-save.', [
            'command' => $applyCommand,
        ]);

        return [
            'ok' => true,
            'strategy' => 'apply',
            'apply_result' => $applyResult,
            'restart_result' => null,
        ];
    }

    kioskLog('dashboard', 'Runtime apply mislukt na config-save, restart fallback gestart.', [
        'command' => $applyCommand,
        'exit_code' => (int)$applyResult['exit_code'],
        'output' => (string)$applyResult['output'],
    ]);

    $restartResult = runCommandWithStatus($restartCommand);
    if ((int)$restartResult['exit_code'] === 0) {
        kioskLog('dashboard', 'Kiosk restart fallback gelukt na config-save.', [
            'command' => $restartCommand,
        ]);

        return [
            'ok' => true,
            'strategy' => 'restart',
            'apply_result' => $applyResult,
            'restart_result' => $restartResult,
        ];
    }

    kioskLog('dashboard', 'Kiosk restart fallback mislukt na config-save.', [
        'command' => $restartCommand,
        'exit_code' => (int)$restartResult['exit_code'],
        'output' => (string)$restartResult['output'],
    ]);

    return [
        'ok' => false,
        'strategy' => 'failed',
        'apply_result' => $applyResult,
        'restart_result' => $restartResult,
    ];
}

function badgeClass(string $status): string {
    $status = strtolower(trim($status));
    return match ($status) {
        'active', 'running', 'up', 'ok' => 'ok',
        'inactive', 'failed', 'down', 'error' => 'bad',
        default => 'warn',
    };
}

function badgeText(string $status): string {
    $status = strtolower(trim($status));
    return match ($status) {
        'active'   => 'Actief',
        'inactive' => 'Inactief',
        'failed'   => 'Fout',
        'running'  => 'Actief',
        default    => $status !== '' ? ucfirst($status) : 'Onbekend',
    };
}

function boolToString(bool $value): string {
    return $value ? 'true' : 'false';
}

function stringToBool(string $value, bool $default = true): bool {
    $value = strtolower(trim($value));
    return match ($value) {
        'true', '1', 'yes', 'on'  => true,
        'false', '0', 'no', 'off' => false,
        default                   => $default,
    };
}
require_once __DIR__ . '/kiosk_runtime_helpers.php';

function collectPostedConfig(array $post, array $fallback): array {
    $type = in_array($post['type'] ?? '', ['website', 'presentation'], true)
        ? (string)$post['type']
        : (string)$fallback['type'];

    $url = trim((string)($post['url'] ?? $fallback['url']));
    $slideSeconds = (int)($post['slide_seconds'] ?? $fallback['slide_seconds']);
    $refreshSeconds = (int)($post['refresh_seconds'] ?? $fallback['refresh_seconds']);
    $cacheHours = (int)($post['cache_hours'] ?? $fallback['cache_hours']);

    $slideStart = isset($post['slide_start']);
    $slideLoop = isset($post['slide_loop']);

    $onTime = preg_replace('/[^0-9:]/', '', (string)($post['on_time'] ?? $fallback['on_time']));
    $offTime = preg_replace('/[^0-9:]/', '', (string)($post['off_time'] ?? $fallback['off_time']));

    if (looksLikeGoogleSlides($url)) {
        $type = 'presentation';
    }

    return [
        'type'            => $type,
        'url'             => $url,
        'kiosk_mode'      => (string)($fallback['kiosk_mode'] ?? 'website'),
        'selected_preset_url' => trim((string)($fallback['selected_preset_url'] ?? '')),
        'sequence_key'    => trim((string)($fallback['sequence_key'] ?? '')),
        'resolved_preset_url' => trim((string)($fallback['resolved_preset_url'] ?? '')),
        'sequence_items'  => normalizeSequenceItems($fallback['sequence_items'] ?? []),
        'sequence_items_defined' => (bool)($fallback['sequence_items_defined'] ?? false),
        'timezone'        => normalizeTimezoneName((string)($fallback['timezone'] ?? detectSystemTimezone())),
        'slide_seconds'   => $slideSeconds,
        'slide_start'     => $slideStart,
        'slide_loop'      => $slideLoop,
        'refresh_seconds' => $refreshSeconds,
        'cache_hours'     => $cacheHours,
        'on_time'         => $onTime,
        'off_time'        => $offTime,
    ];
}

function validatePostedConfig(array $cfg): ?string {
    if ((int)$cfg['slide_seconds'] < 1 || (int)$cfg['slide_seconds'] > 3600) {
        return 'Presentatie timing moet tussen 1 en 3600 seconden liggen.';
    }
    if ((int)$cfg['refresh_seconds'] < 0 || (int)$cfg['refresh_seconds'] > 86400) {
        return 'Refresh tijd moet tussen 0 en 86400 seconden liggen.';
    }
    if ((int)$cfg['cache_hours'] < 0 || (int)$cfg['cache_hours'] > 168) {
        return 'Cache interval moet tussen 0 en 168 uur liggen.';
    }
    if (($cfg['on_time'] !== '' && !preg_match('/^\d{2}:\d{2}$/', (string)$cfg['on_time'])) ||
        ($cfg['off_time'] !== '' && !preg_match('/^\d{2}:\d{2}$/', (string)$cfg['off_time']))) {
        return 'Start- en stoptijd moeten in formaat UU:MM staan.';
    }
    return null;
}

function buildAppliedConfigForSelection(array $draft, ?array $selectedPreset, array $presets, array $sequenceItems = []): array {
    $applied = $draft;
    $applied['timezone'] = normalizeTimezoneName((string)($draft['timezone'] ?? detectSystemTimezone()));
    $applied['sequence_items'] = normalizeSequenceItems($sequenceItems);
    $applied['sequence_items_defined'] = true;

    if (is_array($selectedPreset)) {
        if ($applied['sequence_items'] !== []) {
            $resolved = resolveSequenceRuntimeData($selectedPreset, $applied['sequence_items'], $presets, $applied);
            $applied['type'] = trim((string)($resolved['type'] ?? normalizeSourcePresetType(
                (string)($selectedPreset['type'] ?? ''),
                trim((string)($selectedPreset['url'] ?? ''))
            )));
            $applied['kiosk_mode'] = 'sequence';
            $applied['selected_preset_url'] = trim((string)$selectedPreset['url']);
            $applied['sequence_key'] = '';
            $applied['resolved_preset_url'] = trim((string)($resolved['resolved_preset_url'] ?? ''));
            $applied['url'] = trim((string)($resolved['runtime_url'] ?? ''));

            return $applied;
        }

        $presetUrl = trim((string)$selectedPreset['url']);
        $presetType = normalizeSourcePresetType((string)($selectedPreset['type'] ?? ''), $presetUrl);
        $applied['type'] = $presetType;
        $applied['kiosk_mode'] = $presetType;
        $applied['selected_preset_url'] = $presetUrl;
        $applied['sequence_key'] = '';
        $applied['resolved_preset_url'] = $presetUrl;
        $applied['url'] = normalizeContentUrl(
            $presetType,
            $presetUrl,
            (bool)$applied['slide_start'],
            (bool)$applied['slide_loop'],
            (int)$applied['slide_seconds']
        );

        return $applied;
    }

    $draftType = normalizeSourcePresetType((string)$draft['type'], trim((string)$draft['url']));
    $applied['type'] = $draftType;
    $applied['kiosk_mode'] = $draftType;
    $applied['selected_preset_url'] = '';
    $applied['sequence_key'] = '';
    $applied['resolved_preset_url'] = trim((string)$draft['url']);
    $applied['url'] = normalizeContentUrl(
        $draftType,
        trim((string)$draft['url']),
        (bool)$applied['slide_start'],
        (bool)$applied['slide_loop'],
        (int)$applied['slide_seconds']
    );

    return $applied;
}

function validateAppliedConfig(array $cfg): ?string {
    if (!filter_var((string)$cfg['url'], FILTER_VALIDATE_URL)) {
        if (($cfg['kiosk_mode'] ?? '') === 'sequence') {
            return 'Sequence override heeft geen geldige runtime-URL.';
        }

        return 'Ongeldige URL. Vul een volledige URL in, bijvoorbeeld: https://example.com';
    }

    return null;
}

function decodePostedSequenceItems(array $post): array {
    $rawJson = trim((string)($post['preset_sequence_data'] ?? '[]'));
    if ($rawJson === '' || strtolower($rawJson) === 'null') {
        return ['items' => [], 'error' => null];
    }

    $decoded = json_decode($rawJson, true);
    if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
        return ['items' => [], 'error' => 'Sequence data kon niet gelezen worden. Herlaad de pagina en probeer opnieuw.'];
    }

    if (!is_array($decoded)) {
        return ['items' => [], 'error' => 'Sequence data is ongeldig ontvangen.'];
    }

    $items = normalizeSequenceItems($decoded);
    if (count($items) !== count($decoded)) {
        return ['items' => [], 'error' => 'Een of meer sequence tijdsloten zijn ongeldig ingevuld.'];
    }

    return ['items' => $items, 'error' => null];
}

function collectPostedPresetPayload(array $post): array {
    $presetName = trim((string)($post['preset_name'] ?? ''));
    $presetDescription = trim((string)($post['preset_description'] ?? ''));
    $presetSourceUrl = trim((string)($post['preset_manage_url'] ?? ($post['url'] ?? '')));

    return buildPresetPayload(
        $presetName,
        $presetDescription,
        $presetSourceUrl,
        (string)($post['type'] ?? 'website')
    );
}

function validatePostedPresetPayload(array $presetPayload): ?string {
    $presetName = trim((string)($presetPayload['name'] ?? ''));
    $presetUrl = trim((string)($presetPayload['url'] ?? ''));

    if ($presetName === '' || $presetUrl === '') {
        return 'Voor een preset zijn naam en URL verplicht.';
    }

    if (!filter_var($presetUrl, FILTER_VALIDATE_URL)) {
        return 'Ongeldige preset-URL.';
    }

    return null;
}

function loadDashboardAuth(string $file, string $fallbackUser, string $fallbackPass): array {
    $user = trim($fallbackUser);
    $pass = $fallbackPass;
    $passHash = '';

    if (is_readable($file)) {
        $data = @parse_ini_file($file, false, INI_SCANNER_RAW);
        if (is_array($data)) {
            $rawUser = trim((string)($data['DASHBOARD_USER'] ?? ''));
            $rawPass = (string)($data['DASHBOARD_PASS'] ?? '');
            $rawHash = trim((string)($data['DASHBOARD_PASS_HASH'] ?? ''));

            if ($rawUser !== '') {
                $user = $rawUser;
            }
            if ($rawPass !== '') {
                $pass = $rawPass;
            }
            if ($rawHash !== '') {
                $passHash = $rawHash;
            }
        }
    }

    if ($user === '') {
        $user = 'beheer';
    }
    if ($pass === '' && $passHash === '') {
        $pass = $fallbackPass;
    }

    return [
        'user' => $user,
        'pass' => $pass,
        'pass_hash' => $passHash,
    ];
}

function verifyDashboardPassword(string $enteredPassword, string $plainPassword, string $passwordHash): bool {
    if ($passwordHash !== '') {
        return password_verify($enteredPassword, $passwordHash);
    }

    return hash_equals($plainPassword, $enteredPassword);
}

function renderAuthLoginPage(string $csrf, string $error, string $username, int $lockSeconds): void {
    http_response_code(401);
?>
<!doctype html>
<html lang="nl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Beveiligde toegang</title>
  <link rel="stylesheet" href="style.css">
</head>
<body class="page-auth">
  <main class="auth-shell">
    <section class="panel auth-card">
      <div class="auth-head">
        <span class="badge">Kiosk</span>
        <div>
          <h1>Beveiligde toegang</h1>
          <div class="small">Log in om het dashboard te gebruiken.</div>
        </div>
      </div>

      <?php if ($error !== ''): ?>
        <div class="error"><?= h($error) ?></div>
      <?php endif; ?>

      <?php if ($lockSeconds > 0): ?>
        <div class="notice">Wacht nog <?= (int)$lockSeconds ?> seconden voor je opnieuw probeert.</div>
      <?php endif; ?>

      <form method="post" class="auth-form" autocomplete="on">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="auth_action" value="login">

        <div class="form-row">
          <label for="auth_user">Gebruikersnaam</label>
          <input
            type="text"
            id="auth_user"
            name="auth_user"
            value="<?= h($username) ?>"
            autocomplete="username"
            required
            autofocus
          >
        </div>

        <div class="form-row">
          <label for="auth_pass">Wachtwoord</label>
          <input
            type="password"
            id="auth_pass"
            name="auth_pass"
            autocomplete="current-password"
            required
          >
        </div>

        <button type="submit">Inloggen</button>
      </form>

      <div class="auth-help">
        Standaard login actief. Verander deze via
        <code>/etc/default/kiosk-dashboard-auth.conf</code>.
      </div>
    </section>
  </main>
</body>
</html>
<?php
    exit;
}

/* =========================================================
   CSRF
   ========================================================= */
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csrf'];

/* =========================================================
   Toegang beveiliging
   ========================================================= */
$auth = loadDashboardAuth($AUTH_CONFIG_FILE, $AUTH_DEFAULT_USER, $AUTH_DEFAULT_PASS);
$authUser = (string)$auth['user'];
$authPass = (string)$auth['pass'];
$authPassHash = (string)$auth['pass_hash'];
$authError = '';
$authUserInput = '';

if ($AUTH_ENABLED) {
    if (!isset($_SESSION['auth_failed_count'])) {
        $_SESSION['auth_failed_count'] = 0;
    }
    if (!isset($_SESSION['auth_lock_until'])) {
        $_SESSION['auth_lock_until'] = 0;
    }

    $requestPath = strtok((string)($_SERVER['REQUEST_URI'] ?? 'index.php'), '?');
    if ($requestPath === false || $requestPath === '') {
        $requestPath = 'index.php';
    }

    $logoutRequested = false;
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['auth_action'] ?? '') === 'logout') {
        if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
            $authError = 'Ongeldige sessie. Meld opnieuw aan.';
        } else {
            $logoutRequested = true;
        }
    } elseif ((string)($_GET['logout'] ?? '') === '1') {
        $logoutRequested = true;
    }

    if ($logoutRequested) {
        $_SESSION['dashboard_authenticated'] = false;
        unset($_SESSION['dashboard_user']);
        $_SESSION['auth_failed_count'] = 0;
        $_SESSION['auth_lock_until'] = 0;
        session_regenerate_id(true);
        header('Location: ' . $requestPath);
        exit;
    }

    $isAuthenticated = !empty($_SESSION['dashboard_authenticated']);
    $now = time();
    $lockUntil = (int)($_SESSION['auth_lock_until'] ?? 0);

    if (!$isAuthenticated && $_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['auth_action'] ?? '') === 'login') {
        $authUserInput = trim((string)($_POST['auth_user'] ?? ''));
        $authPassInput = (string)($_POST['auth_pass'] ?? '');

        if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
            $authError = 'Ongeldige sessie. Herlaad en probeer opnieuw.';
        } elseif ($lockUntil > $now) {
            $authError = 'Tijdelijk geblokkeerd na te veel foute pogingen.';
        } else {
            $userOk = hash_equals($authUser, $authUserInput);
            $passOk = verifyDashboardPassword($authPassInput, $authPass, $authPassHash);

            if ($userOk && $passOk) {
                $_SESSION['dashboard_authenticated'] = true;
                $_SESSION['dashboard_user'] = $authUser;
                $_SESSION['auth_failed_count'] = 0;
                $_SESSION['auth_lock_until'] = 0;
                session_regenerate_id(true);
                header('Location: ' . $requestPath);
                exit;
            }

            $failedCount = (int)($_SESSION['auth_failed_count'] ?? 0) + 1;
            $_SESSION['auth_failed_count'] = $failedCount;

            if ($failedCount >= $AUTH_MAX_ATTEMPTS) {
                $_SESSION['auth_failed_count'] = 0;
                $_SESSION['auth_lock_until'] = time() + $AUTH_LOCK_SECONDS;
                $authError = 'Te veel mislukte pogingen. Probeer straks opnieuw.';
            } else {
                $remaining = max(0, $AUTH_MAX_ATTEMPTS - $failedCount);
                $authError = 'Onjuiste inloggegevens. Nog ' . $remaining . ' poging(en).';
            }
        }
    }

    $isAuthenticated = !empty($_SESSION['dashboard_authenticated']);
    $lockSeconds = max(0, (int)($_SESSION['auth_lock_until'] ?? 0) - time());

    if (!$isAuthenticated) {
        renderAuthLoginPage($csrf, $authError, $authUserInput, $lockSeconds);
    }
} else {
    $_SESSION['dashboard_authenticated'] = true;
    $_SESSION['dashboard_user'] = 'local';
}

/* =========================================================
   Defaults
   ========================================================= */
$defaults = [
    'type'            => 'website',
    'url'             => 'http://localhost/',
    'kiosk_mode'      => 'website',
    'selected_preset_url' => '',
    'sequence_key'    => '',
    'resolved_preset_url' => '',
    'sequence_items'  => [],
    'sequence_items_defined' => false,
    'timezone'        => detectSystemTimezone(),
    'slide_seconds'   => 10,
    'slide_start'     => true,
    'slide_loop'      => true,
    'refresh_seconds' => 30,
    'cache_hours'     => 2,
    'on_time'         => '',
    'off_time'        => '',
];

$PRESETS = loadPresets($PRESETS_FILE);
$config = loadKioskConfig($CONFIG_FILE, $defaults);

/* =========================================================
   Huidige systeeminfo
   ========================================================= */
$piModel        = @file_exists('/proc/device-tree/model') ? trim((string)file_get_contents('/proc/device-tree/model')) : '';
$osPretty       = sh("grep PRETTY_NAME /etc/os-release | cut -d= -f2 | tr -d '\"'");
$arch           = php_uname('m');
$apache         = sh('apache2 -v | head -n1');
$chromium       = sh('chromium-browser --version 2>/dev/null || chromium --version 2>/dev/null');
$phpv           = PHP_VERSION;
$uptime         = sh('uptime -p 2>/dev/null');
$ip_eth0        = sh("ip -4 addr show eth0 | awk '/inet / {print $2}' | cut -d/ -f1");
$ip_wlan0       = sh("ip -4 addr show wlan0 | awk '/inet / {print $2}' | cut -d/ -f1");
$sshStatus      = sh('systemctl is-active ssh 2>/dev/null');
$kioskStatus    = sh('systemctl is-active kiosk.service 2>/dev/null');
$refreshStatus  = sh('systemctl is-active refresh.service 2>/dev/null');
$hostName       = sh('hostname');

$sshBadgeClass      = badgeClass($sshStatus);
$sshBadgeText       = badgeText($sshStatus);
$kioskBadgeClass    = badgeClass($kioskStatus);
$kioskBadgeText     = badgeText($kioskStatus);
$refreshBadgeClass  = badgeClass($refreshStatus);
$refreshBadgeText   = badgeText($refreshStatus);

$networkIp = $ip_wlan0 ?: ($ip_eth0 ?: 'Geen IP beschikbaar');

/* =========================================================
   Huidige preset bepalen
   ========================================================= */
$currentPresetState = determineCurrentPresetState($PRESETS, $config);
$currentPreset = (string)($currentPresetState['selected_url'] ?? '');
$currentPresetData = is_array($currentPresetState['preset'] ?? null)
    ? $currentPresetState['preset']
    : null;
$currentConfiguredSequenceItems = is_array($currentPresetData)
    ? resolveConfiguredSequenceItems($config, $currentPresetData)
    : [];
$currentSequenceRuntime = is_array($currentPresetData) && $currentConfiguredSequenceItems !== []
    ? resolveSequenceRuntimeData($currentPresetData, $currentConfiguredSequenceItems, $PRESETS, $config)
    : null;

/* =========================================================
   Meldingen
   ========================================================= */
$notice = '';
$error  = '';
$lastAction = '';

$presetManageSelected = '';
$presetManageName     = '';
$presetManageDescription = '';
$presetManageUrl      = '';
$sequenceFormStateItems = normalizeSequenceItems($config['sequence_items'] ?? []);

/* =========================================================
   Form verwerking
   ========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'save';
    $intentAction = trim((string)($_POST['intent_action'] ?? ''));
    $presetModePosted = trim((string)($_POST['preset_mode'] ?? ''));

    if ($intentAction === 'preset_commit' && in_array($presetModePosted, ['preset_add', 'preset_update', 'preset_delete'], true)) {
        $action = $presetModePosted;
    }

    $lastAction = (string)$action;

    if (!hash_equals($csrf, $_POST['csrf'] ?? '')) {
        $error = 'Ongeldige sessie (CSRF). Herlaad de pagina en probeer opnieuw.';
    } else {
        if ($action === 'save') {
            $selectedPresetUrl = trim((string)($_POST['preset_url'] ?? ''));
            $selectedPreset = $selectedPresetUrl !== '' ? findPresetByUrl($PRESETS, $selectedPresetUrl) : null;
            $draftPresetMode = in_array($presetModePosted, ['preset_add', 'preset_update'], true) ? $presetModePosted : '';
            $draft = collectPostedConfig($_POST, $config);
            $decodedSequence = decodePostedSequenceItems($_POST);
            $sequenceFormStateItems = (array)($decodedSequence['items'] ?? []);
            $validationError = validatePostedConfig($draft);

            if (($decodedSequence['error'] ?? null) !== null) {
                $error = (string)$decodedSequence['error'];
            } elseif ($validationError !== null) {
                $error = $validationError;
            } else {
                if ($draftPresetMode !== '') {
                    $draftPresetPayload = collectPostedPresetPayload($_POST);
                    $presetManageName = trim((string)($draftPresetPayload['name'] ?? ''));
                    $presetManageDescription = trim((string)($draftPresetPayload['description'] ?? ''));
                    $presetManageUrl = trim((string)($draftPresetPayload['url'] ?? ''));
                    $presetManageSelected = trim((string)($_POST['selected_preset'] ?? $selectedPresetUrl));

                    $draftPresetError = validatePostedPresetPayload($draftPresetPayload);
                    if ($draftPresetError !== null) {
                        $error = $draftPresetError;
                    } elseif ($draftPresetMode === 'preset_add') {
                        $existingIndex = findPresetIndexByUrl($PRESETS, (string)$draftPresetPayload['url']);
                        if ($existingIndex >= 0) {
                            $error = 'Er bestaat al een preset met deze URL.';
                        } else {
                            $PRESETS[] = $draftPresetPayload;
                            if (!savePresets($PRESETS_FILE, $PRESETS)) {
                                $error = 'Kon preset-bestand niet opslaan.';
                            } else {
                                $selectedPreset = $draftPresetPayload;
                                $selectedPresetUrl = trim((string)$draftPresetPayload['url']);
                                $presetManageSelected = $selectedPresetUrl;
                                $currentPreset = $selectedPresetUrl;
                                kioskLog('dashboard', 'Preset toegevoegd tijdens config-save.', [
                                    'preset' => $selectedPresetUrl,
                                    'mode' => 'preset_add',
                                ]);
                            }
                        }
                    } else {
                        $selectedUpdateUrl = trim((string)($_POST['selected_preset'] ?? $selectedPresetUrl));
                        if ($selectedUpdateUrl === '') {
                            $error = 'Kies eerst een preset om te wijzigen.';
                        } else {
                            $index = findPresetIndexByUrl($PRESETS, $selectedUpdateUrl);
                            if ($index < 0) {
                                $error = 'Gekozen preset niet gevonden.';
                            } else {
                                $duplicateIndex = findPresetIndexByUrl($PRESETS, (string)$draftPresetPayload['url']);
                                if ($duplicateIndex >= 0 && $duplicateIndex !== $index) {
                                    $error = 'Er bestaat al een preset met deze URL.';
                                } else {
                                    $PRESETS[$index] = array_merge(
                                        is_array($PRESETS[$index]) ? $PRESETS[$index] : [],
                                        $draftPresetPayload
                                    );

                                    if (!savePresets($PRESETS_FILE, $PRESETS)) {
                                        $error = 'Kon preset-bestand niet opslaan.';
                                    } else {
                                        $selectedPreset = $PRESETS[$index];
                                        $selectedPresetUrl = trim((string)($selectedPreset['url'] ?? ''));
                                        $presetManageSelected = $selectedPresetUrl;
                                        $currentPreset = $selectedPresetUrl;
                                        kioskLog('dashboard', 'Preset bijgewerkt tijdens config-save.', [
                                            'preset' => $selectedPresetUrl,
                                            'mode' => 'preset_update',
                                        ]);
                                    }
                                }
                            }
                        }
                    }
                }

                if ($error === '' && $selectedPreset === null) {
                    $error = 'Kies eerst een hoofdpreset in stap 1.';
                }

                $sequenceValidationError = validateSequenceItemsAgainstPresets(
                    $sequenceFormStateItems,
                    $PRESETS,
                    $selectedPresetUrl
                );

                if ($error !== '') {
                    $presetManageSelected = $selectedPresetUrl;
                } elseif ($sequenceValidationError !== null) {
                    $error = $sequenceValidationError;
                } else {
                    if (!syncPresetSequenceItems($PRESETS, $selectedPresetUrl, $sequenceFormStateItems)) {
                        $error = 'De sequence kon niet aan de gekozen preset gekoppeld worden.';
                    } elseif (!savePresets($PRESETS_FILE, $PRESETS)) {
                        $error = 'Kon preset-bestand niet opslaan.';
                    } else {
                        $selectedPreset = findPresetByUrl($PRESETS, $selectedPresetUrl);
                    }
                }

                if ($error === '') {
                    $appliedConfig = buildAppliedConfigForSelection($draft, $selectedPreset, $PRESETS, $sequenceFormStateItems);
                    $runtimeError = validateAppliedConfig($appliedConfig);

                    if ($runtimeError !== null) {
                        $error = $runtimeError;
                    } else {
                        $config = $appliedConfig;
                        $presetManageSelected = $selectedPresetUrl;

                        if (!writeKioskConfig($CONFIG_FILE, $config)) {
                            $error = "Kon {$CONFIG_FILE} niet schrijven. Controleer permissies.";
                        } else {
                            kioskLog('dashboard', 'Configuratie opgeslagen.', [
                                'preset' => $selectedPresetUrl,
                                'mode' => (string)($config['kiosk_mode'] ?? 'website'),
                                'sequence_items' => count((array)($config['sequence_items'] ?? [])),
                            ]);

                            if ($RESTART_AFTER_SAVE) {
                                $runtimeApply = applyRuntimeAfterConfigSave(
                                    $CMD_APPLY_RUNTIME,
                                    $CMD_RESTART_KIOSK,
                                    isWithinOperatingSchedule($config),
                                    $CMD_SEQUENCE_ONCE
                                );
                                if ((bool)($runtimeApply['ok'] ?? false)) {
                                    $notice = match ((string)($runtimeApply['strategy'] ?? '')) {
                                        'restart' => 'Configuratie opgeslagen. Directe wissel mislukte, kiosk is herstart om de nieuwe inhoud toe te passen.',
                                        'deferred' => 'Configuratie opgeslagen. De kiosk staat nu buiten het tijdschema en is direct uitgeschakeld. Op het Pi aan-moment wordt de gekozen preset automatisch getoond.',
                                        default => 'Configuratie opgeslagen. Nieuwe inhoud is toegepast.',
                                    };
                                } else {
                                    $error = 'Configuratie opgeslagen, maar de kiosk kon de nieuwe inhoud niet automatisch laden. Controleer /home/pi/kiosk-runtime.log.';
                                }
                            } else {
                                $notice = 'Configuratie opgeslagen.';
                            }
                        }
                    }
                }
            }
        } elseif ($action === 'preset_add') {
            $preset_name = trim((string)($_POST['preset_name'] ?? ''));
            $preset_description = trim((string)($_POST['preset_description'] ?? ''));
            $preset_url = trim((string)($_POST['preset_manage_url'] ?? ''));
            $presetPayload = buildPresetPayload(
                $preset_name,
                $preset_description,
                $preset_url,
                (string)($_POST['type'] ?? 'website')
            );

            $presetManageName = $preset_name;
            $presetManageDescription = $preset_description;
            $presetManageUrl = $preset_url;

            if ($preset_name === '' || $preset_url === '') {
                $error = 'Voor een nieuwe preset zijn naam en URL verplicht.';
            } elseif (!filter_var($preset_url, FILTER_VALIDATE_URL)) {
                $error = 'Ongeldige preset-URL.';
            } else {
                $existingIndex = findPresetIndexByUrl($PRESETS, (string)$presetPayload['url']);

                if ($existingIndex >= 0) {
                    $error = 'Er bestaat al een preset met deze URL.';
                } else {
                    $PRESETS[] = $presetPayload;

                    if (savePresets($PRESETS_FILE, $PRESETS)) {
                        $currentPreset = (string)$presetPayload['url'];
                        $presetManageSelected = (string)$presetPayload['url'];
                        kioskLog('dashboard', 'Preset toegevoegd.', ['preset' => $currentPreset]);
                        $notice = 'Nieuwe preset toegevoegd.';
                        $presetManageName = '';
                        $presetManageDescription = '';
                        $presetManageUrl = '';
                    } else {
                        $error = 'Kon preset-bestand niet opslaan.';
                    }
                }
            }
        } elseif ($action === 'preset_update') {
            $selected_url = trim((string)($_POST['selected_preset'] ?? ''));
            $preset_name = trim((string)($_POST['preset_name'] ?? ''));
            $preset_description = trim((string)($_POST['preset_description'] ?? ''));
            $preset_url = trim((string)($_POST['preset_manage_url'] ?? ''));

            $presetManageSelected = $selected_url;
            $presetManageName = $preset_name;
            $presetManageDescription = $preset_description;
            $presetManageUrl = $preset_url;

            if ($selected_url === '') {
                $error = 'Kies eerst een preset om te wijzigen.';
            } elseif ($preset_name === '' || $preset_url === '') {
                $error = 'Voor wijzigen zijn naam en URL verplicht.';
            } elseif (!filter_var($preset_url, FILTER_VALIDATE_URL)) {
                $error = 'Ongeldige preset-URL.';
            } else {
                $index = findPresetIndexByUrl($PRESETS, $selected_url);

                if ($index < 0) {
                    $error = 'Gekozen preset niet gevonden.';
                } else {
                    $presetPayload = buildPresetPayload(
                        $preset_name,
                        $preset_description,
                        $preset_url,
                        (string)($_POST['type'] ?? 'website')
                    );
                    $duplicateIndex = findPresetIndexByUrl($PRESETS, (string)$presetPayload['url']);

                    if ($duplicateIndex >= 0 && $duplicateIndex !== $index) {
                        $error = 'Er bestaat al een preset met deze URL.';
                    } else {
                        $oldPresetUrl = trim((string)($PRESETS[$index]['url'] ?? ''));
                        $PRESETS[$index] = array_merge(
                            is_array($PRESETS[$index]) ? $PRESETS[$index] : [],
                            $presetPayload
                        );

                        if (
                            $oldPresetUrl !== ''
                            && !urlsReferToSamePreset($oldPresetUrl, (string)$presetPayload['url'])
                        ) {
                            rewritePresetSequenceReferences($PRESETS, $oldPresetUrl, (string)$presetPayload['url']);
                        }

                        if (savePresets($PRESETS_FILE, $PRESETS)) {
                            $configWasUpdated = false;
                            $currentSequenceItems = normalizeSequenceItems($config['sequence_items'] ?? []);
                            $updatedSequenceItems = [];

                            foreach ($currentSequenceItems as $item) {
                                if ($oldPresetUrl !== '' && urlsReferToSamePreset((string)$item['preset_url'], $oldPresetUrl)) {
                                    $item['preset_url'] = (string)$presetPayload['url'];
                                    $configWasUpdated = true;
                                }
                                $updatedSequenceItems[] = $item;
                            }

                            $selectedConfigPreset = null;
                            if ($oldPresetUrl !== '' && urlsReferToSamePreset((string)($config['selected_preset_url'] ?? ''), $oldPresetUrl)) {
                                $selectedConfigPreset = $presetPayload;
                                $configWasUpdated = true;
                            } elseif (trim((string)($config['selected_preset_url'] ?? '')) !== '') {
                                $selectedConfigPreset = findPresetByUrl($PRESETS, trim((string)$config['selected_preset_url']));
                            }

                            if (is_array($selectedConfigPreset) && $configWasUpdated) {
                                $rebuiltConfig = buildAppliedConfigForSelection(
                                    $config,
                                    $selectedConfigPreset,
                                    $PRESETS,
                                    $updatedSequenceItems
                                );
                                $config = $rebuiltConfig;
                            } elseif ($configWasUpdated) {
                                $config['sequence_items'] = $updatedSequenceItems;
                                $config['sequence_items_defined'] = true;
                            }

                            if ($configWasUpdated && !writeKioskConfig($CONFIG_FILE, $config)) {
                                $error = 'Preset gewijzigd, maar kioskconfig kon niet worden bijgewerkt.';
                            } else {
                                if ($configWasUpdated && $RESTART_AFTER_SAVE) {
                                    $runtimeApply = applyRuntimeAfterConfigSave(
                                        $CMD_APPLY_RUNTIME,
                                        $CMD_RESTART_KIOSK,
                                        isWithinOperatingSchedule($config),
                                        $CMD_SEQUENCE_ONCE
                                    );
                                    if (!(bool)($runtimeApply['ok'] ?? false)) {
                                        $error = 'Preset gewijzigd, maar de kiosk kon de nieuwe inhoud niet automatisch laden. Controleer /home/pi/kiosk-runtime.log.';
                                    } elseif ($notice === '') {
                                        $notice = match ((string)($runtimeApply['strategy'] ?? '')) {
                                            'restart' => 'Preset gewijzigd. De kiosk is herstart om de wijziging toe te passen.',
                                            'deferred' => 'Preset gewijzigd. De kiosk staat nu buiten het tijdschema en is direct uitgeschakeld. Op het Pi aan-moment wordt de gekozen preset automatisch getoond.',
                                            default => 'Preset gewijzigd en direct toegepast.',
                                        };
                                    }
                                }
                                kioskLog('dashboard', 'Preset gewijzigd.', ['preset' => (string)$presetPayload['url']]);
                                if ($notice === '' && $error === '') {
                                    $notice = 'Preset gewijzigd.';
                                }
                                $currentPreset = (string)$presetPayload['url'];
                                $presetManageSelected = (string)$presetPayload['url'];
                            }
                        } else {
                            $error = 'Kon preset-bestand niet opslaan.';
                        }
                    }
                }
            }
        } elseif ($action === 'preset_delete') {
            $selected_url = trim($_POST['selected_preset'] ?? '');
            $presetManageSelected = $selected_url;

            if ($selected_url === '') {
                $error = 'Kies eerst een preset om te verwijderen.';
            } else {
                $index = findPresetIndexByUrl($PRESETS, $selected_url);

                if ($index < 0) {
                    $error = 'Gekozen preset niet gevonden.';
                } else {
                    $deletedPreset = is_array($PRESETS[$index]) ? $PRESETS[$index] : null;
                    $deletedUrl = trim((string)($deletedPreset['url'] ?? ''));
                    unset($PRESETS[$index]);
                    rewritePresetSequenceReferences($PRESETS, $deletedUrl, '');

                    if (savePresets($PRESETS_FILE, $PRESETS)) {
                        $PRESETS = array_values($PRESETS);
                        kioskLog('dashboard', 'Preset verwijderd.', ['preset' => $selected_url]);
                        $notice = 'Preset verwijderd.';
                        $configWasUpdated = false;

                        $configSelectedPreset = trim((string)($config['selected_preset_url'] ?? ''));
                        $currentSequenceItems = normalizeSequenceItems($config['sequence_items'] ?? []);
                        $filteredSequenceItems = [];

                        foreach ($currentSequenceItems as $item) {
                            if ($deletedUrl !== '' && urlsReferToSamePreset((string)$item['preset_url'], $deletedUrl)) {
                                $configWasUpdated = true;
                                continue;
                            }
                            $filteredSequenceItems[] = $item;
                        }

                        if ($deletedUrl !== '' && urlsReferToSamePreset($configSelectedPreset, $deletedUrl)) {
                            $config['selected_preset_url'] = '';
                            $config['resolved_preset_url'] = '';
                            $config['sequence_key'] = '';
                            $config['sequence_items'] = [];
                            $config['sequence_items_defined'] = true;
                            $config['kiosk_mode'] = normalizeSourcePresetType((string)($config['type'] ?? 'website'), trim((string)($config['url'] ?? '')));
                            $configWasUpdated = true;
                        } elseif ($configWasUpdated) {
                            $selectedPreset = $configSelectedPreset !== ''
                                ? findPresetByUrl($PRESETS, $configSelectedPreset)
                                : null;

                            if (is_array($selectedPreset)) {
                                $config = buildAppliedConfigForSelection(
                                    $config,
                                    $selectedPreset,
                                    $PRESETS,
                                    $filteredSequenceItems
                                );
                            } else {
                                $config['sequence_items'] = $filteredSequenceItems;
                                $config['sequence_items_defined'] = true;
                            }
                        }

                        if ($configWasUpdated && !writeKioskConfig($CONFIG_FILE, $config)) {
                            $error = 'Preset verwijderd, maar kioskconfig kon niet opgeschoond worden.';
                        } elseif ($configWasUpdated && $RESTART_AFTER_SAVE) {
                            $runtimeApply = applyRuntimeAfterConfigSave(
                                $CMD_APPLY_RUNTIME,
                                $CMD_RESTART_KIOSK,
                                isWithinOperatingSchedule($config),
                                $CMD_SEQUENCE_ONCE
                            );
                            if (!(bool)($runtimeApply['ok'] ?? false)) {
                                $error = 'Preset verwijderd, maar de kiosk kon de nieuwe inhoud niet automatisch laden. Controleer /home/pi/kiosk-runtime.log.';
                            } else {
                                $notice = match ((string)($runtimeApply['strategy'] ?? '')) {
                                    'restart' => 'Preset verwijderd. De kiosk is herstart om de wijziging toe te passen.',
                                    'deferred' => 'Preset verwijderd. De kiosk staat nu buiten het tijdschema en is direct uitgeschakeld. Op het Pi aan-moment wordt de gekozen preset automatisch getoond.',
                                    default => 'Preset verwijderd en direct toegepast.',
                                };
                            }
                        }

                        $currentPreset = '';
                        $presetManageSelected = '';
                        $presetManageName = '';
                        $presetManageUrl = '';
                    } else {
                        $error = 'Kon preset-bestand niet opslaan.';
                    }
                }
            }
        } elseif ($action === 'restart_kiosk') {
            shell_exec($CMD_RESTART_KIOSK . ' > /dev/null 2>&1 &');
            kioskLog('dashboard', 'Handmatige kiosk restart gestart.');
            $notice = 'Kioskservice wordt herstart...';
        } elseif ($action === 'refresh') {
            shell_exec($CMD_REFRESH_ONLY . ' > /dev/null 2>&1 &');
            kioskLog('dashboard', 'Handmatige refresh gestart.');
            $notice = 'Refresh-script gestart...';
        } elseif ($action === 'reboot') {
            shell_exec($CMD_REBOOT_PI . ' > /dev/null 2>&1 &');
            kioskLog('dashboard', 'Handmatige reboot gestart.');
            $notice = 'Reboot gestart...';
        } elseif ($action === 'ssh_start') {
            shell_exec($CMD_SSH_START . ' > /dev/null 2>&1 &');
            kioskLog('dashboard', 'SSH start aangevraagd.');
            $notice = 'SSH gestart. VS Code Remote SSH kan nu verbinden.';
        } elseif ($action === 'ssh_stop') {
            shell_exec($CMD_SSH_STOP . ' > /dev/null 2>&1 &');
            kioskLog('dashboard', 'SSH stop aangevraagd.');
            $notice = 'SSH gestopt. VS Code Remote SSH is nu geblokkeerd.';
        } elseif ($action === 'clear_cache') {
            shell_exec($CMD_CLEAR_CACHE . ' > /dev/null 2>&1 &');
            kioskLog('dashboard', 'Chromium cache clear aangevraagd.');
            $notice = 'Chromium cache wordt leeggemaakt...';
        }
    }
}

/* =========================================================
   Afgeleide waarden
   ========================================================= */
$previewUrl = normalizeContentUrl(
    $config['type'],
    $config['url'],
    (bool)$config['slide_start'],
    (bool)$config['slide_loop'],
    (int)$config['slide_seconds']
);

$currentPresetState = determineCurrentPresetState($PRESETS, $config);
$currentPreset = trim((string)($currentPresetState['selected_url'] ?? ''));
$currentPresetData = is_array($currentPresetState['preset'] ?? null)
    ? $currentPresetState['preset']
    : null;
$currentConfiguredSequenceItems = is_array($currentPresetData)
    ? resolveConfiguredSequenceItems($config, $currentPresetData)
    : [];
$currentSequenceRuntime = is_array($currentPresetData) && $currentConfiguredSequenceItems !== []
    ? resolveSequenceRuntimeData($currentPresetData, $currentConfiguredSequenceItems, $PRESETS, $config)
    : null;
$isSavePostback = $_SERVER['REQUEST_METHOD'] === 'POST' && $lastAction === 'save';
$initialSequenceOwnerUrl = $presetManageSelected !== '' ? $presetManageSelected : $currentPreset;
$initialSequencePreset = $initialSequenceOwnerUrl !== ''
    ? findPresetByUrl($PRESETS, $initialSequenceOwnerUrl)
    : null;
$initialSequenceItems = $isSavePostback
    ? $sequenceFormStateItems
    : (
        $initialSequenceOwnerUrl !== ''
        && $currentPreset !== ''
        && urlsReferToSamePreset($initialSequenceOwnerUrl, $currentPreset)
            ? $currentConfiguredSequenceItems
            : []
    );
$initialSequenceJson = json_encode(
    normalizeSequenceItems($initialSequenceItems),
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
);
if ($initialSequenceJson === false) {
    $initialSequenceJson = '[]';
}

$currentTypeLabel = 'Website';
$currentModeText  = 'Websitemodus actief';
$currentSequenceSummary = '';
$currentSequenceNextSummary = '';
if (is_array($currentPresetData)) {
    $currentTypeLabel = normalizeSourcePresetType(
        (string)($currentPresetData['type'] ?? ''),
        trim((string)($currentPresetData['url'] ?? ''))
    ) === 'presentation'
        ? 'Google Presentatie'
        : 'Website';
}

if (is_array($currentPresetData) && $currentConfiguredSequenceItems !== []) {
    if (is_array($currentSequenceRuntime)) {
        if (($currentSequenceRuntime['status'] ?? '') === 'active') {
            $currentTypeLabel = (($currentSequenceRuntime['type'] ?? 'website') === 'presentation')
                ? 'Google Presentatie'
                : 'Website';
            $currentModeText = 'Tijdslot override actief';
            $currentSequenceSummary = formatSequenceSlotSummary(
                is_array($currentSequenceRuntime['active_slot'] ?? null) ? $currentSequenceRuntime['active_slot'] : null,
                $PRESETS
            );
        } elseif (($currentSequenceRuntime['status'] ?? '') === 'fallback') {
            $currentTypeLabel = (($currentSequenceRuntime['type'] ?? 'website') === 'presentation')
                ? 'Google Presentatie'
                : 'Website';
            $currentModeText = 'Hoofdpreset actief';
            $currentSequenceSummary = 'Buiten tijdsloten tonen we de hoofdpreset.';
        } else {
            $currentModeText = 'Override zonder geldig doel';
            $currentSequenceSummary = 'Geen geldig tijdslot of hoofdpreset gevonden.';
        }

        $currentSequenceNextSummary = formatSequenceSlotSummary(
            is_array($currentSequenceRuntime['next_slot'] ?? null) ? $currentSequenceRuntime['next_slot'] : null,
            $PRESETS
        );
    }
} elseif (($config['kiosk_mode'] ?? '') === 'sequence') {
    $currentModeText = 'Geplande overrides actief';
} elseif ($config['type'] === 'presentation') {
    $currentTypeLabel = 'Google Presentatie';
    $currentModeText = 'Presentatiemodus actief';
}
?>
<!doctype html>
<html lang="nl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Kiosk Configuratie</title>
  <link rel="stylesheet" href="style.css">
  <link rel="stylesheet" href="home.css">
</head>
<body class="page-home">

<div class="gear-fab-wrap">
  <button type="button" class="gear-fab" id="gearFab" aria-label="Open beheer dashboard">
    &#9881;
    <span class="gear-ip-tooltip" id="gearIpTooltip">
      IP: <?= h($networkIp) ?>
    </span>
  </button>
</div>

<div class="modal-backdrop" id="beheerModal">
  <div class="modal-card">
    <button type="button" class="modal-close" id="closeBeheerModal" aria-label="Sluiten">&times;</button>

    <div class="modal-header">
      <div class="badge">Beheer</div>
      <div>
        <h2>Beheer Dashboard</h2>
        <div class="small">Netwerk en technische functies op een plaats</div>
      </div>
    </div>

    <div class="dashboard-grid beheer-top-stats">
      <div class="stat-card">
        <div class="stat-label">Kiosk service</div>
        <div class="stat-value"><span class="status-pill <?= h($kioskBadgeClass) ?>"><?= h($kioskBadgeText) ?></span></div>
      </div>

      <div class="stat-card">
        <div class="stat-label">Refresh service</div>
        <div class="stat-value"><span class="status-pill <?= h($refreshBadgeClass) ?>"><?= h($refreshBadgeText) ?></span></div>
      </div>

      <div class="stat-card">
        <div class="stat-label">SSH service</div>
        <div class="stat-value"><span class="status-pill <?= h($sshBadgeClass) ?>"><?= h($sshBadgeText) ?></span></div>
      </div>

      <div class="stat-card">
        <div class="stat-label">Hostname</div>
        <div class="stat-value"><?= h($hostName ?: 'Onbekend') ?></div>
      </div>

      <div class="stat-card">
        <div class="stat-label">Ethernet IP</div>
        <div class="stat-value"><?= h($ip_eth0 ?: 'Niet verbonden') ?></div>
      </div>

      <div class="stat-card">
        <div class="stat-label">Wi-Fi IP</div>
        <div class="stat-value"><?= h($ip_wlan0 ?: 'Niet verbonden') ?></div>
      </div>
    </div>

    <div class="modal-grid">
      <section class="soft-card">
        <h3 class="subsection-title">Netwerk</h3>
        <div class="kv-grid">
          <div class="k">Hostname</div>
          <div class="v"><?= h($hostName ?: 'Onbekend') ?></div>

          <div class="k">Wi-Fi IP</div>
          <div class="v"><?= h($ip_wlan0 ?: 'Niet verbonden') ?></div>

          <div class="k">Ethernet IP</div>
          <div class="v"><?= h($ip_eth0 ?: 'Niet verbonden') ?></div>

          <div class="k">Actief IP</div>
          <div class="v"><?= h($networkIp) ?></div>
        </div>
      </section>

      <section class="soft-card">
        <h3 class="subsection-title">Cache en services</h3>
        <div class="actions">
          <button type="submit" form="configForm" name="action" value="refresh" class="ghost">Nu verversen</button>
          <button type="submit" form="configForm" name="action" value="clear_cache" class="ghost">Cache legen</button>
          <button type="submit" form="configForm" name="action" value="restart_kiosk" class="ghost">Kiosk herstarten</button>
          <button type="submit" form="configForm" name="action" value="ssh_start" class="ghost">SSH starten</button>
          <button type="submit" form="configForm" name="action" value="ssh_stop" class="ghost">SSH stoppen</button>
        </div>
      </section>

      <section class="soft-card">
        <h3 class="subsection-title">Wi-Fi instellen</h3>
        <div class="form-row">
          <label for="beheer_ssid">SSID</label>
          <input type="text" id="beheer_ssid" placeholder="Bijvoorbeeld MijnWifi">
        </div>

        <div class="form-row">
          <label for="beheer_psk">Wachtwoord</label>
          <input type="password" id="beheer_psk" placeholder="Wi-Fi wachtwoord">
        </div>

        <div class="helper">Dit blok is voorbereid voor netwerkbeheer in hetzelfde dashboard.</div>
      </section>

      <section class="soft-card">
        <h3 class="subsection-title">Logfiles en info</h3>
        <div class="form-row">
          <label for="ict_mail">Mailadres ICT-co</label>
          <input type="email" id="ict_mail" placeholder="ict@example.be">
        </div>

        <div class="actions">
          <button type="button" class="ghost">Logfile bekijken</button>
          <button type="button" class="ghost">Logfile doorsturen</button>
        </div>

        <div class="helper">Later kunnen we hier ook debuggegevens en slave logs toevoegen.</div>
      </section>

      <section class="soft-card soft-card-wide systeminfo-table-card">
        <h3 class="subsection-title">Systeeminformatie</h3>

        <table class="systeminfo-table">
          <tr>
            <th>Raspberry Pi</th>
            <td><?= h($piModel ?: 'Onbekend') ?></td>
          </tr>
          <tr>
            <th>Besturingssysteem</th>
            <td><?= h($osPretty ?: php_uname('s')) ?> (<?= h($arch) ?>)</td>
          </tr>
          <tr>
            <th>Apache</th>
            <td><?= h($apache ?: 'apache2 niet gevonden') ?></td>
          </tr>
          <tr>
            <th>Chromium</th>
            <td><?= h($chromium ?: 'Chromium niet gevonden') ?></td>
          </tr>
          <tr>
            <th>PHP</th>
            <td><?= h($phpv) ?></td>
          </tr>
          <tr>
            <th>Uptime</th>
            <td><?= h($uptime ?: 'n.v.t.') ?></td>
          </tr>
        </table>

        <div class="footer-note small">&copy; Valentijn Rombaut 2026</div>
      </section>
    </div>
  </div>
</div>

<header class="header">
  <div class="title">
    <span class="badge">Kiosk</span>
    <div>
      <h1>Kiosk Configuratie Dashboard</h1>
      <div class="small">Beheer je Raspberry Pi signage systeem op een plek</div>
    </div>
    <div class="header-actions">
      <span class="small">Ingelogd als <?= h((string)($_SESSION['dashboard_user'] ?? $authUser)) ?></span>
      <form method="post" class="logout-form">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="auth_action" value="logout">
        <button type="submit" class="ghost logout-btn">Uitloggen</button>
      </form>
    </div>
  </div>
</header>

<main class="container">
  <?php if ($notice): ?>
    <div class="notice flash-message" data-flash><?= h($notice) ?></div>
  <?php endif; ?>

  <?php if ($error): ?>
    <div class="error flash-message" data-flash><?= h($error) ?></div>
  <?php endif; ?>

  <section class="onboarding-card" id="onboardingCard" aria-live="polite">
    <h2 class="section-title">Toelichting</h2>
    <div class="small">Volg deze 4 stappen.</div>
    <div class="onboarding-steps">
      <div class="onboarding-step"><strong>1. Inhoud:</strong> Kies een preset of maak een nieuwe preset aan.</div>
      <div class="onboarding-step"><strong>2. Sequence:</strong> Plan tijdelijke overrides voor je hoofdpreset.</div>
      <div class="onboarding-step"><strong>3. Instellingen:</strong> Controleer refresh en planning.</div>
      <div class="onboarding-step"><strong>4. Controleer:</strong> Kijk alles na en sla op.</div>
    </div>
  </section>

  <section class="panel highlight">
    <h2 class="section-title">Huidige instelling</h2>

    <div class="hero-grid" style="margin-top:16px;">
      <div class="preview-card">
        <div class="small" id="currentModeText"><?= h($currentModeText) ?></div>
        <h3 style="margin:8px 0 10px 0;">Beeld</h3>
        <div><code id="previewUrlTop"><?= h($previewUrl) ?></code></div>

        <div class="preview-meta">
          <div class="mini">
            <div class="mini-label">Type</div>
            <div class="mini-value"><?= h($currentTypeLabel) ?></div>
          </div>
          <div class="mini">
            <div class="mini-label">Refresh (snel)</div>
            <div class="mini-value"><?= (int)$config['refresh_seconds'] ?> sec</div>
          </div>
          <div class="mini" id="previewSlideMeta" <?= $config['type'] === 'presentation' ? '' : 'style="display:none;"' ?>>
            <div class="mini-label">Duur diaovergang</div>
            <div class="mini-value" id="previewSlideSeconds"><?= (int)$config['slide_seconds'] ?> sec</div>
          </div>
        </div>

        <?php if ($currentSequenceSummary !== ''): ?>
          <div class="helper" style="margin-top:12px;">Actieve override: <?= h($currentSequenceSummary) ?></div>
        <?php endif; ?>

        <?php if ($currentSequenceNextSummary !== ''): ?>
          <div class="helper">Volgende override: <?= h($currentSequenceNextSummary) ?></div>
        <?php endif; ?>
      </div>

      <div class="summary-card">
        <h3 style="margin:8px 0 10px 0;">Raspberry-Pi</h3>
        <div class="kv-grid" style="margin-top:12px;">
          <div class="k">Start automatisch</div>
          <div class="v"><?= $config['slide_start'] ? 'Ja' : 'Nee' ?></div>

          <div class="k">Cache legen (trage refresh van 15sec)</div>
          <div class="v"><?= (int)$config['cache_hours'] ?> uur</div>

          <div class="k">Pi aan</div>
          <div class="v"><?= h($config['on_time'] !== '' ? $config['on_time'] : 'Niet ingesteld') ?></div>

          <div class="k">Pi uit</div>
          <div class="v"><?= h($config['off_time'] !== '' ? $config['off_time'] : 'Niet ingesteld') ?></div>
        </div>
      </div>
    </div>
  </section>

  <form method="post" novalidate autocomplete="off" id="configForm" data-last-action="<?= h($lastAction) ?>" data-has-error="<?= $error !== '' ? '1' : '0' ?>">
    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
    <input type="hidden" id="selected_preset" name="selected_preset" value="<?= h($presetManageSelected) ?>">
    <input type="hidden" id="preset_manage_url" name="preset_manage_url" value="<?= h($presetManageUrl !== '' ? $presetManageUrl : $config['url']) ?>">
    <input type="hidden" id="intentAction" name="intent_action" value="">
    <input type="hidden" id="presetModeInput" name="preset_mode" value="">
    <input type="hidden" id="presetSequenceData" name="preset_sequence_data" value="<?= h($initialSequenceJson) ?>">

    <section class="panel panel-kiosk-builder">
      <h2 class="section-title section-title-main">1) Kiosk instellen</h2>

      <div class="wizard-stepper" id="wizardStepper" role="tablist" aria-label="Instelstappen">
        <button type="button" class="wizard-step active" data-step-target="1" aria-selected="true">1. Inhoud</button>
        <button type="button" class="wizard-step" data-step-target="2" aria-selected="false">2. Sequence</button>
        <button type="button" class="wizard-step" data-step-target="3" aria-selected="false">3. Instellingen</button>
        <button type="button" class="wizard-step" data-step-target="4" aria-selected="false">4. Controleer</button>
      </div>

      <div class="wizard-panel" data-step="1">
        <div class="kiosk-wire-box">
          <h3 class="subsection-title">Presets</h3>
          <div class="small">Stap 1: kies inhoud en beheer presets.</div>

          <div class="kiosk-table-row kiosk-preset-row">
            <label for="preset_url">Kies preset</label>
            <div class="kiosk-preset-controls">
              <select id="preset_url" name="preset_url">
                <option value="">-- Kies preset --</option>
                <?php foreach ($PRESETS as $preset): ?>
                  <?php $presetUrl = (string)$preset['url']; ?>
                  <option value="<?= h($presetUrl) ?>" <?= $presetManageSelected === $presetUrl ? 'selected' : '' ?>>
                    <?= h((string)$preset['name']) ?>
                  </option>
                <?php endforeach; ?>
              </select>

              <div class="kiosk-mode-buttons" aria-label="Preset acties">
                <button type="button" class="preset-mode-btn mode-add" data-preset-mode="preset_add" title="Preset toevoegen" aria-label="Preset toevoegen">+</button>
                <button type="button" class="preset-mode-btn mode-update" data-preset-mode="preset_update" title="Preset wijzigen" aria-label="Preset wijzigen">&#9998;</button>
                <button type="button" class="preset-mode-btn mode-delete" data-preset-mode="preset_delete" title="Preset verwijderen" aria-label="Preset verwijderen">&#128465;</button>
              </div>
            </div>
          </div>

          <span class="kiosk-warning hidden" id="presetDeleteWarning">Verwijderen vereist 2 bevestigingen.</span>
          <div class="helper hidden" id="presetViewHint"></div>

          <div class="kiosk-info-grid <?= $presetManageSelected === '' ? 'hidden' : '' ?>" id="presetInfoGrid">
            <div class="kiosk-cell">
              <div class="kiosk-cell-head">Toon soort</div>
              <div class="kiosk-cell-body" id="currentTypeLabelBox"><?= h($currentTypeLabel) ?></div>
            </div>
            <div class="kiosk-cell">
              <div class="kiosk-cell-head">Toon URL</div>
              <div class="kiosk-cell-body"><code id="previewUrl"><?= h($previewUrl) ?></code></div>
            </div>
          </div>

          <hr class="sep hidden" id="presetEditorSep">

          <div class="kiosk-editor hidden" id="presetEditor">
            <h3 class="subsection-title" id="presetEditorTitle">Preset toevoegen</h3>

            <div class="kiosk-editor-grid kiosk-editor-top">
              <div class="form-row">
                <label class="editor-label" for="preset_name">Naam</label>
                <input
                  type="text"
                  id="preset_name"
                  name="preset_name"
                  placeholder="Bijvoorbeeld: School presentatie"
                  value="<?= h($presetManageName) ?>"
                >
              </div>

              <div class="form-row">
                <label class="editor-label" for="preset_description">Omschrijving</label>
                <textarea
                  id="preset_description"
                  name="preset_description"
                  rows="3"
                  placeholder="Korte uitleg (meerdere regels mogelijk)."
                ><?= h($presetManageDescription) ?></textarea>
              </div>
            </div>

            <div class="kiosk-editor-grid kiosk-editor-type-sec">
              <div class="form-row">
                <label class="editor-label" for="type_select">Soort</label>
                <select id="type_select" name="type">
                  <option value="website" <?= $config['type'] === 'website' ? 'selected' : '' ?>>Website</option>
                  <option value="presentation" <?= $config['type'] === 'presentation' ? 'selected' : '' ?>>Google Presentatie</option>
                </select>
              </div>

              <div class="form-row" id="slideRow">
                <label class="editor-label" for="slide_seconds">SEC</label>
                <input id="slide_seconds" name="slide_seconds" type="number" min="1" max="3600" value="<?= (int)$config['slide_seconds'] ?>">
              </div>
            </div>

            <input type="checkbox" id="slide_loop" name="slide_loop" <?= $config['slide_loop'] ? 'checked' : '' ?> class="hidden" aria-hidden="true" tabindex="-1">

            <div class="form-row kiosk-editor-url">
              <label class="editor-label" for="url">URL</label>
              <input
                id="url"
                name="url"
                type="url"
                placeholder="https://voorbeeld.be"
                value="<?= h($presetManageUrl !== '' ? $presetManageUrl : $config['url']) ?>"
              >
              <div class="helper">Plak de volledige URL van een Google Presentatie of website.</div>
            </div>

            <div class="kiosk-final-row">
              <div class="form-row">
                <label class="editor-label">Uiteindelijke URL</label>
                <code id="editorFinalUrl"><?= h($previewUrl) ?></code>
              </div>
              <button type="button" class="ghost" id="testUrl">Testknop</button>
            </div>

            <div class="actions preset-editor-actions">
              <button type="submit" class="primary-btn hidden" id="presetCommitBtn" name="action" value="preset_add">Opslaan</button>
              <button type="button" class="ghost hidden" id="cancelPresetMode">Annuleren</button>
            </div>
          </div>
        </div>
      </div>

      <div class="wizard-panel hidden" data-step="2">
        <div class="kiosk-wire-box sequence-step-shell">
          <div class="sequence-builder-heading">
            <div>
              <h3 class="subsection-title">Sequence overrides</h3>
              <div class="small">Stap 2: kies tijdelijke overrides voor de hoofdpreset.</div>
            </div>
          </div>

          <div class="helper" id="sequenceReadOnlyHint">Kies in stap 1 eerst een hoofdpreset.</div>

          <div class="sequence-selector-row" id="sequenceControlRow">
            <div class="form-row sequence-selector-field">
              <label class="editor-label" for="sequencePresetSelect">Selecteer override preset</label>
              <select id="sequencePresetSelect">
                <option value="">-- Selecteer override preset --</option>
                <?php foreach ($PRESETS as $preset): ?>
                  <option value="<?= h((string)$preset['url']) ?>"><?= h((string)$preset['name']) ?></option>
                <?php endforeach; ?>
              </select>
              <div class="small" id="sequenceSourceHint">Kies eerst een hoofdpreset in stap 1.</div>
            </div>

            <div class="sequence-selector-actions">
              <button type="button" class="preset-mode-btn mode-add sequence-open-btn" id="sequenceAddBtn" title="Tijdslot toevoegen" aria-label="Tijdslot toevoegen">+</button>
            </div>
          </div>

          <div class="form-row hidden" id="sequenceCustomUrlRow">
            <label class="editor-label" for="sequenceCustomUrl">Directe override URL</label>
            <input
              type="url"
              id="sequenceCustomUrl"
              placeholder="https://voorbeeld.be/pagina"
            >
            <div class="small">Gebruik dit alleen als de override nog geen preset is.</div>
          </div>

          <div class="sequence-builder-card hidden" id="sequenceBuilderCard">
            <div class="sequence-builder-card-head">
              <div>
                <h4 class="sequence-builder-title" id="sequenceBuilderTitle">Tijdslot toevoegen</h4>
                <div class="small" id="sequenceBuilderSubtitle">Kies start en stop.</div>
              </div>
            </div>

            <div class="sequence-slot-rows" id="sequenceSlotRows"></div>

            <div class="actions sequence-builder-actions">
              <button type="button" class="ghost" id="sequenceAddTimeBtn">Nog een tijdslot toevoegen</button>
              <button type="button" class="primary-btn" id="sequenceCommitBtn">Toevoegen</button>
              <button type="button" class="ghost" id="sequenceCancelBtn">Annuleren</button>
            </div>
          </div>

          <div class="sequence-list-card">
            <div class="sequence-list-head">
              <div>
                <h4 class="sequence-builder-title">Overrides</h4>
                <div class="small" id="sequenceCountText">Nog geen tijdsloten</div>
              </div>
            </div>

            <div class="sequence-empty helper" id="sequenceEmptyState">Nog geen overrides toegevoegd. Kies een hoofdpreset, klik op het plus-teken en plan je tijdsloten.</div>
            <div class="sequence-items" id="sequenceItemsList"></div>
          </div>
        </div>
      </div>

      <div class="wizard-panel hidden" data-step="3">
        <div class="kiosk-wire-box">
          <h3 class="subsection-title">Instellingen raspberry Pi</h3>
          <div class="small">Stap 3: stel timing en dagplanning in.</div>

          <div class="kiosk-settings-grid">
            <div class="form-row">
              <label for="refresh_seconds">Automatisch verversen (seconden)</label>
              <input id="refresh_seconds" name="refresh_seconds" type="number" min="0" max="86400" value="<?= (int)$config['refresh_seconds'] ?>">
            </div>

            <div class="form-row">
              <label for="cache_hours">Cache legen om de hoeveel uur</label>
              <input id="cache_hours" name="cache_hours" type="number" min="0" max="168" value="<?= (int)$config['cache_hours'] ?>">
            </div>

            <div class="form-row">
              <label for="on_time">Pi aan om (UU:MM)</label>
              <input id="on_time" name="on_time" type="time" value="<?= h($config['on_time']) ?>">
            </div>

            <div class="form-row">
              <label for="off_time">Pi uit om (UU:MM)</label>
              <input id="off_time" name="off_time" type="time" value="<?= h($config['off_time']) ?>">
            </div>
          </div>

          <div class="kiosk-start-row" id="slideStartRow">
            <label class="checkbox-row">
              <input type="checkbox" id="slide_start" name="slide_start" <?= $config['slide_start'] ? 'checked' : '' ?>>
              <span>Start automatisch (alleen voor presentaties)</span>
            </label>
          </div>
        </div>
      </div>

      <div class="wizard-panel hidden" data-step="4">
        <div class="kiosk-wire-box">
          <h3 class="subsection-title">Controleer</h3>
          <div class="small">Stap 4: controleer inhoud en instellingen voordat je opslaat.</div>

          <div class="wizard-summary-grid">
            <div class="kiosk-cell">
              <div class="kiosk-cell-head">Preset</div>
              <div class="kiosk-cell-body" id="summaryPreset">Nog geen preset gekozen</div>
            </div>
            <div class="kiosk-cell">
              <div class="kiosk-cell-head">Type</div>
              <div class="kiosk-cell-body" id="summaryType"><?= h($currentTypeLabel) ?></div>
            </div>
            <div class="kiosk-cell">
              <div class="kiosk-cell-head">URL</div>
              <div class="kiosk-cell-body"><code id="summaryUrl"><?= h($previewUrl) ?></code></div>
            </div>
            <div class="kiosk-cell">
              <div class="kiosk-cell-head">Refresh</div>
              <div class="kiosk-cell-body" id="summaryRefresh"><?= (int)$config['refresh_seconds'] ?> sec</div>
            </div>
            <div class="kiosk-cell">
              <div class="kiosk-cell-head">Cache</div>
              <div class="kiosk-cell-body" id="summaryCache"><?= (int)$config['cache_hours'] ?> uur</div>
            </div>
            <div class="kiosk-cell">
              <div class="kiosk-cell-head">Pi aan</div>
              <div class="kiosk-cell-body" id="summaryOnTime"><?= h($config['on_time'] !== '' ? $config['on_time'] : 'Niet ingesteld') ?></div>
            </div>
            <div class="kiosk-cell">
              <div class="kiosk-cell-head">Pi uit</div>
              <div class="kiosk-cell-body" id="summaryOffTime"><?= h($config['off_time'] !== '' ? $config['off_time'] : 'Niet ingesteld') ?></div>
            </div>
          </div>

          <div class="subsection">
            <h4 class="subsection-title">Override overzicht</h4>
            <div class="helper" id="summarySequenceEmpty">Geen overrides gepland.</div>
            <div id="summarySequenceList"></div>
          </div>

          <div class="actions">
            <button type="submit" id="saveBtn" class="save-main" name="action" value="save">Opslaan en toepassen</button>
          </div>
        </div>
      </div>

      <div class="wizard-nav">
        <button type="button" class="ghost" id="wizardPrev">Vorige stap</button>
        <button type="button" class="ghost" id="wizardNext">Volgende stap</button>
      </div>
    </section>
  </form>

</main>

<script>
(function () {
  const WIZARD_MIN_STEP = 1;
  const WIZARD_MAX_STEP = 4;
  const DELETE_WAIT_SECONDS = 3;

  const form = document.getElementById('configForm');
  const flashMessages = document.querySelectorAll('[data-flash]');
  const onboardingCard = document.getElementById('onboardingCard');

  const wizardPanels = document.querySelectorAll('.wizard-panel');
  const wizardStepButtons = document.querySelectorAll('.wizard-step');
  const wizardPrevBtn = document.getElementById('wizardPrev');
  const wizardNextBtn = document.getElementById('wizardNext');

  const typeSelect = document.getElementById('type_select');
  const urlInput = document.getElementById('url');
  const slideInput = document.getElementById('slide_seconds');
  const slideStartInput = document.getElementById('slide_start');
  const slideLoopInput = document.getElementById('slide_loop');
  const onTimeInput = document.getElementById('on_time');
  const offTimeInput = document.getElementById('off_time');
  const slideRow = document.getElementById('slideRow');
  const slideStartRow = document.getElementById('slideStartRow');
  const slideLoopRow = document.getElementById('slideLoopRow');
  const refreshInput = document.getElementById('refresh_seconds');
  const cacheInput = document.getElementById('cache_hours');

  const testBtn = document.getElementById('testUrl');
  const previewUrlEl = document.getElementById('previewUrl');
  const previewUrlTopEl = document.getElementById('previewUrlTop');
  const previewSlideMeta = document.getElementById('previewSlideMeta');
  const previewSlideSeconds = document.getElementById('previewSlideSeconds');
  const currentModeTextEl = document.getElementById('currentModeText');
  const currentTypeLabelBox = document.getElementById('currentTypeLabelBox');
  const editorFinalUrlEl = document.getElementById('editorFinalUrl');

  const presetSelect = document.getElementById('preset_url');
  const presetInfoGrid = document.getElementById('presetInfoGrid');
  const presetViewHint = document.getElementById('presetViewHint');
  const presetEditorSep = document.getElementById('presetEditorSep');
  const selectedPresetInput = document.getElementById('selected_preset');
  const presetNameInput = document.getElementById('preset_name');
  const presetDescriptionInput = document.getElementById('preset_description');
  const presetManageUrlInput = document.getElementById('preset_manage_url');
  const intentActionInput = document.getElementById('intentAction');
  const presetModeInput = document.getElementById('presetModeInput');
  const sequenceDataInput = document.getElementById('presetSequenceData');

  const summaryPresetEl = document.getElementById('summaryPreset');
  const summaryTypeEl = document.getElementById('summaryType');
  const summaryUrlEl = document.getElementById('summaryUrl');
  const summaryRefreshEl = document.getElementById('summaryRefresh');
  const summaryCacheEl = document.getElementById('summaryCache');
  const summaryOnTimeEl = document.getElementById('summaryOnTime');
  const summaryOffTimeEl = document.getElementById('summaryOffTime');
  const summarySequenceEmpty = document.getElementById('summarySequenceEmpty');
  const summarySequenceList = document.getElementById('summarySequenceList');

  const saveBtn = document.getElementById('saveBtn');
  const presetEditor = document.getElementById('presetEditor');
  const presetEditorTitle = document.getElementById('presetEditorTitle');
  const presetCommitBtn = document.getElementById('presetCommitBtn');
  const cancelPresetModeBtn = document.getElementById('cancelPresetMode');
  const presetDeleteWarning = document.getElementById('presetDeleteWarning');
  const presetModeButtons = document.querySelectorAll('[data-preset-mode]');
  const sequenceReadOnlyHint = document.getElementById('sequenceReadOnlyHint');
  const sequenceControlRow = document.getElementById('sequenceControlRow');
  const sequencePresetSelect = document.getElementById('sequencePresetSelect');
  const sequenceCustomUrlRow = document.getElementById('sequenceCustomUrlRow');
  const sequenceCustomUrlInput = document.getElementById('sequenceCustomUrl');
  const sequenceSourceHint = document.getElementById('sequenceSourceHint');
  const sequenceAddBtn = document.getElementById('sequenceAddBtn');
  const sequenceBuilderCard = document.getElementById('sequenceBuilderCard');
  const sequenceBuilderTitle = document.getElementById('sequenceBuilderTitle');
  const sequenceBuilderSubtitle = document.getElementById('sequenceBuilderSubtitle');
  const sequenceSlotRows = document.getElementById('sequenceSlotRows');
  const sequenceAddTimeBtn = document.getElementById('sequenceAddTimeBtn');
  const sequenceCommitBtn = document.getElementById('sequenceCommitBtn');
  const sequenceCancelBtn = document.getElementById('sequenceCancelBtn');
  const sequenceCountText = document.getElementById('sequenceCountText');
  const sequenceEmptyState = document.getElementById('sequenceEmptyState');
  const sequenceItemsList = document.getElementById('sequenceItemsList');

  const gearFab = document.getElementById('gearFab');
  const beheerModal = document.getElementById('beheerModal');
  const closeBeheerModal = document.getElementById('closeBeheerModal');

  let previewWindow = null;
  let autoCloseTimer = null;
  let currentPresetMode = '';
  let currentWizardStep = 1;
  let deleteConfirmArmed = false;
  let deleteConfirmSecondsLeft = 0;
  let deleteConfirmTimer = null;
  let sequenceEditIndex = -1;
  let currentSequenceItems = [];

  const presetMap = {
<?php
$presetJs = [];
foreach ($PRESETS as $preset) {
    $u = json_encode((string)$preset['url'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $n = json_encode((string)$preset['name'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $d = json_encode((string)($preset['description'] ?? ''), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $t = json_encode((string)($preset['type'] ?? 'website'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $s = json_encode(normalizeSequenceItems($preset['sequence'] ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($s === false) {
        $s = '[]';
    }
    $presetJs[] = "    {$u}: { name: {$n}, url: {$u}, description: {$d}, type: {$t}, sequence: {$s} }";
}
echo implode(",\n", $presetJs);
?>

  };
  const initialSequenceOwnerUrl = <?= json_encode((string)$initialSequenceOwnerUrl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

  const presetModeConfig = {
    preset_add: { title: 'Preset toevoegen', submit: 'Opslaan' },
    preset_update: { title: 'Preset wijzigen', submit: 'Opslaan' },
    preset_delete: { title: 'Preset verwijderen', submit: 'verwijderen' }
  };

  function normalizeSequenceItemsState(items) {
    if (!Array.isArray(items)) {
      return [];
    }

    return items
      .map((item) => ({
        preset_url: String(item && item.preset_url ? item.preset_url : '').trim(),
        start_time: String(item && item.start_time ? item.start_time : '').trim(),
        stop_time: String(item && item.stop_time ? item.stop_time : '').trim()
      }))
      .filter((item) => item.preset_url !== '' && /^\d{2}:\d{2}$/.test(item.start_time) && /^\d{2}:\d{2}$/.test(item.stop_time));
  }

  try {
    currentSequenceItems = normalizeSequenceItemsState(JSON.parse(sequenceDataInput ? sequenceDataInput.value || '[]' : '[]'));
  } catch (error) {
    currentSequenceItems = [];
  }
  const initialSequenceSnapshot = normalizeSequenceItemsState(currentSequenceItems);

  function formatTimeOrDefault(value) {
    return value && value.trim() !== '' ? value : 'Niet ingesteld';
  }

  function clampStep(step) {
    return Math.max(WIZARD_MIN_STEP, Math.min(WIZARD_MAX_STEP, step));
  }

  function updateWizardControls() {
    if (wizardPrevBtn) {
      wizardPrevBtn.disabled = currentWizardStep <= WIZARD_MIN_STEP;
    }

    if (wizardNextBtn) {
      wizardNextBtn.disabled = currentWizardStep >= WIZARD_MAX_STEP;
    }
  }

  function setWizardStep(step) {
    currentWizardStep = clampStep(step);

    wizardPanels.forEach((panel) => {
      const panelStep = parseInt(panel.dataset.step || '1', 10);
      const active = panelStep === currentWizardStep;

      panel.classList.toggle('hidden', !active);

      if (active) {
        panel.classList.remove('wizard-panel-enter');
        void panel.offsetWidth;
        panel.classList.add('wizard-panel-enter');
      }
    });

    wizardStepButtons.forEach((button) => {
      const buttonStep = parseInt(button.dataset.stepTarget || '1', 10);
      const active = buttonStep === currentWizardStep;
      button.classList.toggle('active', active);
      button.setAttribute('aria-selected', active ? 'true' : 'false');
    });

    updateWizardControls();
    updateSummary();
  }

  function initOnboarding() {
    if (!onboardingCard) {
      return;
    }
    onboardingCard.classList.remove('hidden');
  }

  function initFlashMessages() {
    flashMessages.forEach((message) => {
      setTimeout(() => {
        message.classList.add('flash-out');
      }, 6500);
    });
  }

  function ensureHttp(url) {
    if (!url) return '';
    if (!/^https?:\/\//i.test(url)) {
      return 'https://' + url;
    }
    return url;
  }

  function isGoogleSlidesUrl(url) {
    return /^https?:\/\/docs\.google\.com\/presentation\/d\/[a-zA-Z0-9_-]+/i.test(url.trim());
  }

  function getSelectedType() {
    return typeSelect ? typeSelect.value : 'website';
  }

  function setType(type) {
    if (typeSelect) {
      typeSelect.value = type === 'presentation' ? 'presentation' : 'website';
    }
  }

  function getTypeLabel(type) {
    return type === 'presentation' ? 'Google Presentatie' : 'Website';
  }

  function getPresetSourceType(preset) {
    if (!preset) {
      return 'website';
    }

    return preset.type === 'presentation' || isGoogleSlidesUrl(String(preset.url || '')) ? 'presentation' : 'website';
  }

  function getPresetSourceUrl(preset) {
    if (!preset) {
      return '';
    }

    return String(preset.url || '');
  }

  function getSequenceSourceOptions() {
    const mainPreset = getMainPresetContext();
    const mainPresetUrl = mainPreset && mainPreset.url ? String(mainPreset.url).trim() : '';

    return Object.values(presetMap).filter((preset) => {
      if (!preset || !preset.url) {
        return false;
      }

      return !(mainPresetUrl !== '' && preset.url === mainPresetUrl);
    });
  }

  function isCustomSequenceSource(url) {
    return !!(url && !presetMap[url]);
  }

  function updateSequenceCustomUrlVisibility() {
    const customSelected = !!(sequencePresetSelect && sequencePresetSelect.value === '__custom__');
    if (sequenceCustomUrlRow) {
      sequenceCustomUrlRow.classList.toggle('hidden', !customSelected);
    }
  }

  function getSequenceSourceValue() {
    if (!sequencePresetSelect) {
      return '';
    }

    if (sequencePresetSelect.value === '__custom__') {
      return ensureHttp(sequenceCustomUrlInput ? sequenceCustomUrlInput.value.trim() : '');
    }

    return sequencePresetSelect.value.trim();
  }

  function syncSequenceHiddenInput() {
    if (sequenceDataInput) {
      sequenceDataInput.value = JSON.stringify(currentSequenceItems);
    }
  }

  function getPresetLabelByUrl(url) {
    const preset = presetMap[url];
    if (preset && preset.name) {
      return preset.name;
    }

    try {
      return new URL(url).hostname.replace(/^www\./i, '') || url;
    } catch (error) {
      return url;
    }
  }

  function getSelectedPresetData() {
    if (!presetSelect) {
      return null;
    }

    const selectedUrl = presetSelect.value.trim();
    if (!selectedUrl || !presetMap[selectedUrl]) {
      return null;
    }

    return presetMap[selectedUrl];
  }

  function getDraftMainPresetData() {
    if (!(currentPresetMode === 'preset_add' || currentPresetMode === 'preset_update')) {
      return null;
    }

    const draftUrl = buildFinalUrl();
    if (!draftUrl) {
      return null;
    }

    return {
      name: presetNameInput && presetNameInput.value.trim() !== '' ? presetNameInput.value.trim() : 'Nieuwe preset',
      url: ensureHttp(urlInput ? urlInput.value.trim() : ''),
      description: presetDescriptionInput ? presetDescriptionInput.value.trim() : '',
      type: getSelectedType()
    };
  }

  function getMainPresetContext() {
    const draftPreset = getDraftMainPresetData();
    if (draftPreset) {
      return draftPreset;
    }

    return getSelectedPresetData();
  }

  function hasChosenPreset() {
    return !!(presetSelect && presetSelect.value && presetSelect.value.trim() !== '');
  }

  function hasPresetContext() {
    return !!getMainPresetContext();
  }

  function isSequenceEditable() {
    return hasPresetContext() && currentPresetMode !== 'preset_delete';
  }

  function canContinueFromStep1() {
    return hasPresetContext();
  }

  function stopDeleteCountdown() {
    if (deleteConfirmTimer) {
      clearInterval(deleteConfirmTimer);
      deleteConfirmTimer = null;
    }
  }

  function resetDeleteConfirmation() {
    stopDeleteCountdown();
    deleteConfirmArmed = false;
    deleteConfirmSecondsLeft = 0;

    if (currentPresetMode === 'preset_delete' && presetModeConfig.preset_delete && presetCommitBtn) {
      presetCommitBtn.textContent = presetModeConfig.preset_delete.submit;
      presetCommitBtn.disabled = false;
    }

    if (presetDeleteWarning) {
      presetDeleteWarning.textContent = 'Verwijderen vereist 2 bevestigingen.';
      presetDeleteWarning.classList.toggle('hidden', currentPresetMode !== 'preset_delete');
    }
  }

  function beginDeleteConfirmation() {
    if (currentPresetMode !== 'preset_delete' || !presetCommitBtn) {
      return;
    }

    if (deleteConfirmArmed || deleteConfirmTimer) {
      return;
    }

    deleteConfirmSecondsLeft = DELETE_WAIT_SECONDS;
    presetCommitBtn.disabled = true;

    if (presetDeleteWarning) {
      presetDeleteWarning.classList.remove('hidden');
      presetDeleteWarning.textContent = `Wacht ${deleteConfirmSecondsLeft} sec voor bevestiging...`;
    }

    deleteConfirmTimer = setInterval(() => {
      deleteConfirmSecondsLeft -= 1;

      if (deleteConfirmSecondsLeft > 0) {
        if (presetDeleteWarning) {
          presetDeleteWarning.textContent = `Wacht ${deleteConfirmSecondsLeft} sec voor bevestiging...`;
        }
        return;
      }

      stopDeleteCountdown();
      deleteConfirmArmed = true;
      presetCommitBtn.disabled = false;
      presetCommitBtn.textContent = 'definitief verwijderen';

      if (presetDeleteWarning) {
        presetDeleteWarning.textContent = 'Klik nu nogmaals op definitief verwijderen.';
      }
    }, 1000);
  }

  function setPresetEditorLock(locked) {
    if (presetNameInput) presetNameInput.readOnly = locked;
    if (presetDescriptionInput) presetDescriptionInput.readOnly = locked;
    if (urlInput) urlInput.readOnly = locked;
    if (typeSelect) typeSelect.disabled = locked;
  }

  function syncHiddenPresetFields() {
    if (selectedPresetInput && presetSelect) {
      selectedPresetInput.value = presetSelect.value;
    }

    if (presetManageUrlInput && urlInput) {
      presetManageUrlInput.value = ensureHttp(urlInput.value.trim());
    }

    syncSequenceHiddenInput();
  }

  function buildFinalUrl() {
    if (!urlInput) return '';

    const rawUrl = ensureHttp(urlInput.value.trim());
    const type = getSelectedType();
    const slideSeconds = Math.max(1, parseInt(slideInput.value || '10', 10));
    const start = slideStartInput.checked;
    const loop = slideLoopInput.checked;

    if (!rawUrl) return '';

    if (type === 'presentation' && isGoogleSlidesUrl(rawUrl)) {
      const match = rawUrl.match(/^https?:\/\/docs\.google\.com\/presentation\/d\/([a-zA-Z0-9_-]+)/i);
      if (match) {
        const id = match[1];
        const params = new URLSearchParams({
          start: start ? 'true' : 'false',
          loop: loop ? 'true' : 'false',
          delayms: String(slideSeconds * 1000)
        });
        return 'https://docs.google.com/presentation/d/' + id + '/present?' + params.toString();
      }
    }

    if (type === 'presentation') {
      let cleaned = rawUrl
        .replace(/([?&])(start|loop|delayms)=[^&]*/gi, '')
        .replace(/[?&]+$/, '');

      const sep = cleaned.includes('?') ? '&' : '?';
      return cleaned + sep
        + 'start=' + (start ? 'true' : 'false')
        + '&loop=' + (loop ? 'true' : 'false')
        + '&delayms=' + (slideSeconds * 1000);
    }

    return rawUrl;
  }

  function updatePresentationFields() {
    const isPresentation = getSelectedType() === 'presentation';

    slideInput.disabled = !isPresentation;
    slideStartInput.disabled = !isPresentation;
    slideLoopInput.disabled = !isPresentation;

    if (slideRow) slideRow.style.display = isPresentation ? '' : 'none';
    if (slideStartRow) slideStartRow.style.opacity = isPresentation ? '1' : '.55';
    if (slideLoopRow) slideLoopRow.style.display = isPresentation ? '' : 'none';
  }

  function updatePresetVisibility() {
    if (!presetInfoGrid || !presetSelect || !presetEditor || !presetEditorTitle || !presetCommitBtn) {
      return;
    }

    const showReadOnlyView = presetSelect.value.trim() !== '' && !isPresetEditorOpen();
    presetInfoGrid.classList.add('hidden');

    if (presetViewHint) {
      presetViewHint.classList.add('hidden');
    }

    if (presetEditorSep) {
      presetEditorSep.classList.toggle('hidden', !(isPresetEditorOpen() || showReadOnlyView));
    }

    if (showReadOnlyView) {
      fillEditorFromSelectedPreset();
      presetEditor.classList.remove('hidden');
      presetEditor.classList.remove('editable-mode');
      presetEditor.classList.add('readonly-mode');
      presetEditorTitle.textContent = 'Preset bekijken';
      presetCommitBtn.classList.add('hidden');
      if (cancelPresetModeBtn) {
        cancelPresetModeBtn.classList.add('hidden');
      }
      setPresetEditorLock(true);
    } else if (!isPresetEditorOpen()) {
      presetEditor.classList.add('hidden');
      presetEditor.classList.remove('readonly-mode');
      presetEditor.classList.remove('editable-mode');
      setPresetEditorLock(false);
    }
  }

  function autoDetectType() {
    if (!urlInput) return;

    const url = ensureHttp(urlInput.value.trim());
    if (isGoogleSlidesUrl(url)) {
      setType('presentation');
    }
  }

  function updatePresetActionAvailability() {
    presetModeButtons.forEach((button) => {
      const mode = button.dataset.presetMode || '';
      if (mode === 'preset_add') {
        button.disabled = false;
        return;
      }

      button.disabled = !hasChosenPreset();
    });
  }

  function shouldReuseInitialSequenceState(selectedUrl) {
    return !!(selectedUrl && initialSequenceOwnerUrl && selectedUrl === initialSequenceOwnerUrl);
  }

  function syncSequenceFromSelectedPreset() {
    const selectedPreset = getSelectedPresetData();
    const selectedUrl = selectedPreset && selectedPreset.url ? String(selectedPreset.url).trim() : '';
    const presetSequence = normalizeSequenceItemsState(selectedPreset && selectedPreset.sequence ? selectedPreset.sequence : []);
    currentSequenceItems = shouldReuseInitialSequenceState(selectedUrl)
      ? normalizeSequenceItemsState(initialSequenceSnapshot)
      : presetSequence;
    renderSequenceSourceOptions();
    renderSequenceList();
    closeSequenceBuilder();
  }

  function renderSequenceSourceOptions(selectedUrl = '') {
    if (!sequencePresetSelect) {
      return;
    }

    const currentSelectValue = sequencePresetSelect.value || '';
    const currentValue = selectedUrl || (currentSelectValue === '__custom__' ? '__custom__' : getSequenceSourceValue() || '');
    const options = getSequenceSourceOptions();
    const customSelected = currentValue === '__custom__' || isCustomSequenceSource(currentValue);
    sequencePresetSelect.innerHTML = '<option value="">-- Selecteer override preset --</option>';

    let foundSelected = false;
    options.forEach((preset) => {
      const option = document.createElement('option');
      option.value = preset.url;
      option.textContent = preset.name;
      if (preset.url === currentValue) {
        option.selected = true;
        foundSelected = true;
      }
      sequencePresetSelect.appendChild(option);
    });

    if (!foundSelected && currentValue !== '' && !customSelected) {
      const option = document.createElement('option');
      option.value = currentValue;
      option.textContent = getPresetLabelByUrl(currentValue);
      option.selected = true;
      sequencePresetSelect.appendChild(option);
    }

    const customOption = document.createElement('option');
    customOption.value = '__custom__';
    customOption.textContent = 'Directe website of presentatie URL';
    if (customSelected) {
      customOption.selected = true;
    }
    sequencePresetSelect.appendChild(customOption);

    if (sequenceCustomUrlInput) {
      if (!customSelected) {
        sequenceCustomUrlInput.value = '';
      } else if (currentValue !== '__custom__') {
        sequenceCustomUrlInput.value = currentValue;
      }
    }

    updateSequenceCustomUrlVisibility();
  }

  function updateSequenceHint() {
    if (!sequenceSourceHint) {
      return;
    }

    if (!hasPresetContext()) {
      sequenceSourceHint.textContent = 'Kies of vul in stap 1 eerst de hoofdpreset in.';
      if (sequenceAddBtn) sequenceAddBtn.disabled = true;
      if (sequencePresetSelect) sequencePresetSelect.disabled = true;
      if (sequenceCustomUrlInput) sequenceCustomUrlInput.disabled = true;
      return;
    }

    if (!isSequenceEditable()) {
      sequenceSourceHint.textContent = 'Annuleer eerst het verwijderen als je overrides wilt wijzigen.';
      if (sequenceAddBtn) sequenceAddBtn.disabled = true;
      if (sequencePresetSelect) sequencePresetSelect.disabled = true;
      if (sequenceCustomUrlInput) sequenceCustomUrlInput.disabled = true;
      return;
    }

    if (sequencePresetSelect) sequencePresetSelect.disabled = false;
    if (sequenceCustomUrlInput) {
      sequenceCustomUrlInput.disabled = !(sequencePresetSelect && sequencePresetSelect.value === '__custom__');
    }

    if (sequencePresetSelect && sequencePresetSelect.value === '__custom__') {
      sequenceSourceHint.textContent = 'Voer een directe override URL in en klik daarna op het plus-teken.';
    } else {
      sequenceSourceHint.textContent = 'Kies een override preset of directe URL en klik daarna op het plus-teken.';
    }
    if (sequenceAddBtn) sequenceAddBtn.disabled = false;
  }

  function updateSequenceEditorState() {
    const hasContext = hasPresetContext();
    const editable = isSequenceEditable();

    if (sequenceControlRow) {
      sequenceControlRow.classList.toggle('hidden', !hasContext);
    }

    if (sequenceCustomUrlRow) {
      sequenceCustomUrlRow.classList.toggle('hidden', !(editable && sequencePresetSelect && sequencePresetSelect.value === '__custom__'));
    }

    if (sequenceReadOnlyHint) {
      if (!hasContext) {
        sequenceReadOnlyHint.textContent = 'Kies of vul in stap 1 eerst de hoofdpreset in.';
        sequenceReadOnlyHint.classList.remove('hidden');
      } else if (!editable) {
        sequenceReadOnlyHint.textContent = 'Preset verwijderen actief. Annuleer dat eerst om overrides te wijzigen.';
        sequenceReadOnlyHint.classList.remove('hidden');
      } else {
        sequenceReadOnlyHint.classList.add('hidden');
      }
    }

    if (!editable) {
      closeSequenceBuilder();
    }
  }

  function refreshSequenceRowButtons() {
    const rows = sequenceSlotRows ? sequenceSlotRows.querySelectorAll('[data-sequence-slot-row]') : [];
    rows.forEach((row) => {
      const removeBtn = row.querySelector('[data-sequence-remove-row]');
      if (removeBtn) {
        removeBtn.disabled = rows.length <= 1;
      }
    });
  }

  function createSequenceSlotRow(start = '', stop = '') {
    const wrapper = document.createElement('div');
    wrapper.className = 'current-url';
    wrapper.dataset.sequenceSlotRow = '1';

    const grid = document.createElement('div');
    grid.className = 'group-grid';

    const startRow = document.createElement('div');
    startRow.className = 'form-row';
    startRow.innerHTML = '<label class="editor-label">Start</label><input type="time" data-sequence-start>';
    startRow.querySelector('input').value = start;

    const stopRow = document.createElement('div');
    stopRow.className = 'form-row';
    stopRow.innerHTML = '<label class="editor-label">Stop</label><input type="time" data-sequence-stop>';
    stopRow.querySelector('input').value = stop;

    grid.appendChild(startRow);
    grid.appendChild(stopRow);

    const actions = document.createElement('div');
    actions.className = 'actions';
    actions.innerHTML = '<button type="button" class="ghost" data-sequence-remove-row>Tijdslot verwijderen</button>';

    wrapper.appendChild(grid);
    wrapper.appendChild(actions);
    return wrapper;
  }

  function appendSequenceSlotRow(start = '', stop = '') {
    if (!sequenceSlotRows) {
      return;
    }
    sequenceSlotRows.appendChild(createSequenceSlotRow(start, stop));
    refreshSequenceRowButtons();
  }

  function closeSequenceBuilder() {
    sequenceEditIndex = -1;
    if (sequenceBuilderCard) sequenceBuilderCard.classList.add('hidden');
    if (sequenceSlotRows) sequenceSlotRows.innerHTML = '';
    if (sequenceBuilderTitle) sequenceBuilderTitle.textContent = 'Tijdslot toevoegen';
    if (sequenceBuilderSubtitle) sequenceBuilderSubtitle.textContent = 'Kies start en stop.';
    if (sequenceCommitBtn) sequenceCommitBtn.textContent = 'Toevoegen';
  }

  function openSequenceBuilder(editIndex = -1) {
    if (!isSequenceEditable()) {
      return;
    }

    renderSequenceSourceOptions();
    updateSequenceHint();

    if (!sequenceBuilderCard || !sequenceSlotRows) {
      return;
    }

    sequenceEditIndex = editIndex;
    sequenceSlotRows.innerHTML = '';

    if (editIndex >= 0 && currentSequenceItems[editIndex]) {
      const item = currentSequenceItems[editIndex];
      renderSequenceSourceOptions(item.preset_url);
      appendSequenceSlotRow(item.start_time, item.stop_time);
      if (sequenceBuilderTitle) sequenceBuilderTitle.textContent = 'Tijdslot wijzigen';
      if (sequenceBuilderSubtitle) sequenceBuilderSubtitle.textContent = 'Pas preset, start en stop aan.';
      if (sequenceCommitBtn) sequenceCommitBtn.textContent = 'Opslaan';
    } else {
      const options = getSequenceSourceOptions();
      if (options[0] && sequencePresetSelect) {
        sequencePresetSelect.value = options[0].url;
      } else if (sequencePresetSelect) {
        sequencePresetSelect.value = '__custom__';
      }
      updateSequenceCustomUrlVisibility();
      appendSequenceSlotRow('', '');
    }

    sequenceBuilderCard.classList.remove('hidden');
  }

  function collectSequenceDraft() {
    if (!sequencePresetSelect || !sequenceSlotRows) {
      return null;
    }

    const presetUrl = getSequenceSourceValue();
    if (!presetUrl) {
      alert('Kies eerst een override preset of vul een directe URL in.');
      return null;
    }

    try {
      new URL(presetUrl);
    } catch (error) {
      alert('De override URL is ongeldig.');
      return null;
    }

    const rows = Array.from(sequenceSlotRows.querySelectorAll('[data-sequence-slot-row]'));
    const items = [];

    for (const row of rows) {
      const start = row.querySelector('[data-sequence-start]')?.value.trim() || '';
      const stop = row.querySelector('[data-sequence-stop]')?.value.trim() || '';
      if (!/^\d{2}:\d{2}$/.test(start) || !/^\d{2}:\d{2}$/.test(stop)) {
        alert('Vul voor elke override een geldige start- en stoptijd in.');
        return null;
      }
      items.push({ preset_url: presetUrl, start_time: start, stop_time: stop });
    }

    return items;
  }

  function renderSequenceList() {
    syncSequenceHiddenInput();
    const editable = isSequenceEditable();

    if (sequenceItemsList) {
      sequenceItemsList.innerHTML = '';
    }

    if (sequenceCountText) {
      sequenceCountText.textContent = currentSequenceItems.length === 0
        ? 'Nog geen tijdsloten'
        : currentSequenceItems.length === 1
          ? '1 override gepland'
          : `${currentSequenceItems.length} overrides gepland`;
    }

    if (sequenceEmptyState) {
      sequenceEmptyState.style.display = currentSequenceItems.length === 0 ? '' : 'none';
    }

    if (!sequenceItemsList) {
      return;
    }

    currentSequenceItems.forEach((item, index) => {
      const row = document.createElement('div');
      row.className = 'sequence-item-row';

      const info = document.createElement('div');
      info.className = 'sequence-item-main';
      info.innerHTML = `<strong class="sequence-item-name">${getPresetLabelByUrl(item.preset_url)}</strong><span class="sequence-item-time">${item.start_time} - ${item.stop_time}</span>`;
      row.appendChild(info);

      if (editable) {
        const actions = document.createElement('div');
        actions.className = 'sequence-item-actions';
        actions.innerHTML = `<button type="button" class="ghost sequence-icon-btn" data-sequence-edit="${index}" aria-label="Tijdslot wijzigen" title="Tijdslot wijzigen">&#9998;</button><button type="button" class="ghost sequence-icon-btn" data-sequence-delete="${index}" aria-label="Tijdslot verwijderen" title="Tijdslot verwijderen">&#128465;</button>`;
        row.appendChild(actions);
      }

      sequenceItemsList.appendChild(row);
    });
  }

  function renderSummarySequence() {
    if (summarySequenceList) {
      summarySequenceList.innerHTML = '';
    }

    if (summarySequenceEmpty) {
      summarySequenceEmpty.style.display = currentSequenceItems.length === 0 ? '' : 'none';
    }

    if (!summarySequenceList) {
      return;
    }

    currentSequenceItems.forEach((item) => {
      const row = document.createElement('div');
      row.className = 'sequence-item-row summary-only';
      row.innerHTML = `<div class="sequence-item-main"><strong class="sequence-item-name">${getPresetLabelByUrl(item.preset_url)}</strong><span class="sequence-item-time">${item.start_time} - ${item.stop_time}</span></div>`;
      summarySequenceList.appendChild(row);
    });
  }

  function fillEditorFromSelectedPreset() {
    if (!presetSelect) return;

    const selected = presetMap[presetSelect.value];
    if (!selected) {
      return;
    }

    if (presetNameInput) presetNameInput.value = selected.name || '';
    if (presetDescriptionInput) presetDescriptionInput.value = selected.description || '';
    if (urlInput) urlInput.value = getPresetSourceUrl(selected);
    setType(getPresetSourceType(selected));
  }

  function getActivePresetLabel() {
    if (currentPresetMode === 'preset_add' && presetNameInput && presetNameInput.value.trim() === '') {
      return 'Nieuwe preset';
    }

    if (isPresetEditorOpen() && presetNameInput && presetNameInput.value.trim() !== '') {
      return presetNameInput.value.trim();
    }

    const preset = getSelectedPresetData();
    if (preset && preset.name) {
      return preset.name;
    }

    return 'Nog geen preset gekozen';
  }

  function setPresetMode(mode) {
    const config = presetModeConfig[mode];
    if (!config || !presetEditor || !presetCommitBtn || !presetEditorTitle) {
      return;
    }

    if ((mode === 'preset_update' || mode === 'preset_delete') && (!presetSelect || !presetSelect.value)) {
      alert('Kies eerst een preset.');
      return;
    }

    currentPresetMode = mode;
    if (presetModeInput) {
      presetModeInput.value = mode;
    }
    presetEditor.classList.remove('hidden');
    presetEditor.classList.remove('readonly-mode');
    presetEditorTitle.textContent = config.title;
    presetCommitBtn.textContent = config.submit;
    presetCommitBtn.value = mode;
    presetCommitBtn.classList.remove('hidden');

    if (cancelPresetModeBtn) {
      cancelPresetModeBtn.classList.remove('hidden');
    }

    if (mode === 'preset_add') {
      if (presetNameInput) presetNameInput.value = '';
      if (presetDescriptionInput) presetDescriptionInput.value = '';
      if (urlInput) urlInput.value = '';
      setType('website');
      currentSequenceItems = [];
      renderSequenceList();
      closeSequenceBuilder();
    } else {
      fillEditorFromSelectedPreset();
    }

    const isDelete = mode === 'preset_delete';
    presetEditor.classList.toggle('editable-mode', !isDelete);
    setPresetEditorLock(isDelete);
    resetDeleteConfirmation();

    if (presetDeleteWarning) {
      presetDeleteWarning.classList.toggle('hidden', !isDelete);
    }

    presetModeButtons.forEach((button) => {
      button.classList.toggle('active', button.dataset.presetMode === mode);
    });

    syncHiddenPresetFields();
    updateSequenceEditorState();
    updatePreview();
  }

  function clearPresetMode() {
    currentPresetMode = '';
    if (presetModeInput) {
      presetModeInput.value = '';
    }

    if (presetEditor) {
      presetEditor.classList.add('hidden');
      presetEditor.classList.remove('readonly-mode');
      presetEditor.classList.remove('editable-mode');
    }

    if (presetCommitBtn) {
      presetCommitBtn.classList.add('hidden');
    }

    if (cancelPresetModeBtn) {
      cancelPresetModeBtn.classList.add('hidden');
    }

    if (presetDeleteWarning) {
      presetDeleteWarning.classList.add('hidden');
    }

    presetModeButtons.forEach((button) => {
      button.classList.remove('active');
    });

    setPresetEditorLock(false);
    resetDeleteConfirmation();
    syncSequenceFromSelectedPreset();
    updateSequenceEditorState();
  }

  function resetFreshPresetLanding() {
    if (selectedPresetInput) {
      selectedPresetInput.value = '';
    }

    if (presetSelect) {
      presetSelect.value = '';
    }

    clearPresetMode();
    currentSequenceItems = [];
    syncSequenceHiddenInput();
    renderSequenceSourceOptions();
    renderSequenceList();
    closeSequenceBuilder();
    updatePresetVisibility();
    updateSequenceEditorState();
    updatePresetActionAvailability();
  }

  function resolveSubmitter(event) {
    if (event.submitter) {
      return event.submitter;
    }

    const activeElement = document.activeElement;
    if (!activeElement) {
      return null;
    }

    const tag = activeElement.tagName;
    if ((tag === 'BUTTON' || tag === 'INPUT') && activeElement.type === 'submit') {
      return activeElement;
    }

    return null;
  }

  function isPresetEditorOpen() {
    return !!(presetEditor && !presetEditor.classList.contains('hidden') && currentPresetMode);
  }

  function markPresetIntent() {
    if (!currentPresetMode) {
      return;
    }

    if (intentActionInput) intentActionInput.value = 'preset_commit';
    if (presetModeInput) presetModeInput.value = currentPresetMode;
  }

  function markSaveIntent() {
    if (intentActionInput) intentActionInput.value = 'save_commit';
    if (presetModeInput) {
      presetModeInput.value = (currentPresetMode === 'preset_add' || currentPresetMode === 'preset_update')
        ? currentPresetMode
        : '';
    }
  }

  function getDisplayState() {
    if (!isPresetEditorOpen() && presetSelect && presetSelect.value && presetMap[presetSelect.value]) {
      const preset = presetMap[presetSelect.value];
      return {
        url: String(preset.url || '').trim(),
        type: getPresetSourceType(preset)
      };
    }

    return {
      url: buildFinalUrl(),
      type: getSelectedType()
    };
  }

  function updateSummary() {
    const display = getDisplayState();
    const refreshValue = refreshInput ? parseInt(refreshInput.value || '0', 10) : 0;
    const cacheValue = cacheInput ? parseInt(cacheInput.value || '0', 10) : 0;
    const onTimeValue = onTimeInput ? onTimeInput.value : '';
    const offTimeValue = offTimeInput ? offTimeInput.value : '';

    if (summaryPresetEl) summaryPresetEl.textContent = getActivePresetLabel();
    if (summaryTypeEl) summaryTypeEl.textContent = getTypeLabel(display.type);
    if (summaryUrlEl) summaryUrlEl.textContent = display.url || 'Nog geen geldige URL ingevuld';
    if (summaryRefreshEl) summaryRefreshEl.textContent = `${refreshValue} sec`;
    if (summaryCacheEl) summaryCacheEl.textContent = `${cacheValue} uur`;
    if (summaryOnTimeEl) summaryOnTimeEl.textContent = formatTimeOrDefault(onTimeValue);
    if (summaryOffTimeEl) summaryOffTimeEl.textContent = formatTimeOrDefault(offTimeValue);
    renderSummarySequence();
  }

  function updatePreview() {
    autoDetectType();
    updatePresentationFields();
    updatePresetActionAvailability();
    updatePresetVisibility();
    updateSequenceEditorState();
    renderSequenceSourceOptions(getSequenceSourceValue());
    syncHiddenPresetFields();

    const display = getDisplayState();
    const finalUrl = buildFinalUrl();
    const text = display.url || 'Nog geen geldige URL ingevuld';
    const selectedType = display.type;

    if (previewUrlEl) previewUrlEl.textContent = text;
    if (previewUrlTopEl) previewUrlTopEl.textContent = text;
    if (editorFinalUrlEl) editorFinalUrlEl.textContent = finalUrl || 'Nog geen geldige URL ingevuld';
    if (previewSlideMeta) {
      previewSlideMeta.style.display = selectedType === 'presentation' ? '' : 'none';
    }
    if (previewSlideSeconds) {
      const seconds = Math.max(1, parseInt(slideInput ? slideInput.value || '10' : '10', 10));
      previewSlideSeconds.textContent = `${seconds} sec`;
    }
    if (currentModeTextEl) {
      currentModeTextEl.textContent = currentSequenceItems.length > 0
        ? 'Overrideplanning actief'
        : selectedType === 'presentation'
          ? 'Presentatiemodus actief'
          : 'Websitemodus actief';
    }
    if (currentTypeLabelBox) currentTypeLabelBox.textContent = getTypeLabel(selectedType);
    updateSummary();
    updateSequenceHint();
  }

  function openBeheerModal() {
    if (!beheerModal) return;

    beheerModal.classList.add('open');

    if (autoCloseTimer) {
      clearTimeout(autoCloseTimer);
    }

    autoCloseTimer = setTimeout(() => {
      beheerModal.classList.remove('open');
    }, 8000);
  }

  function closeModalNow() {
    if (!beheerModal) return;

    beheerModal.classList.remove('open');

    if (autoCloseTimer) {
      clearTimeout(autoCloseTimer);
      autoCloseTimer = null;
    }
  }

  if (typeSelect) {
    typeSelect.addEventListener('change', updatePreview);
  }

  if (selectedPresetInput && presetSelect && selectedPresetInput.value) {
    presetSelect.value = selectedPresetInput.value;
  }

  [urlInput, slideInput, slideStartInput, slideLoopInput, refreshInput, cacheInput, onTimeInput, offTimeInput].forEach(el => {
    if (el) {
      el.addEventListener('input', updatePreview);
      el.addEventListener('change', updatePreview);
    }
  });

  if (presetSelect) {
    presetSelect.addEventListener('change', function () {
      if ((currentPresetMode === 'preset_update' || currentPresetMode === 'preset_delete') && presetSelect.value) {
        fillEditorFromSelectedPreset();
      } else if (!isPresetEditorOpen()) {
        syncSequenceFromSelectedPreset();
      }

      if (!presetSelect.value && (currentPresetMode === 'preset_update' || currentPresetMode === 'preset_delete')) {
        clearPresetMode();
      }

      if (!presetSelect.value && !isPresetEditorOpen()) {
        currentSequenceItems = [];
        renderSequenceList();
        closeSequenceBuilder();
      }

      if (currentPresetMode === 'preset_delete') {
        resetDeleteConfirmation();
      }

      syncHiddenPresetFields();
      updatePreview();
    });
  }

  if (sequencePresetSelect) {
    sequencePresetSelect.addEventListener('change', function () {
      updateSequenceCustomUrlVisibility();
      updateSequenceHint();
    });
  }

  if (sequenceCustomUrlInput) {
    ['input', 'change'].forEach((eventName) => {
      sequenceCustomUrlInput.addEventListener(eventName, function () {
        updateSequenceHint();
      });
    });
  }

  if (sequenceAddBtn) {
    sequenceAddBtn.addEventListener('click', function () {
      openSequenceBuilder(-1);
    });
  }

  if (sequenceAddTimeBtn) {
    sequenceAddTimeBtn.addEventListener('click', function () {
      appendSequenceSlotRow('', '');
    });
  }

  if (sequenceCancelBtn) {
    sequenceCancelBtn.addEventListener('click', function () {
      closeSequenceBuilder();
    });
  }

  if (sequenceCommitBtn) {
    sequenceCommitBtn.addEventListener('click', function () {
      const draftItems = collectSequenceDraft();
      if (!draftItems) {
        return;
      }

      if (sequenceEditIndex >= 0) {
        currentSequenceItems.splice(sequenceEditIndex, 1, ...draftItems);
      } else {
        currentSequenceItems.push(...draftItems);
      }

      renderSequenceList();
      closeSequenceBuilder();
      updatePreview();
    });
  }

  if (sequenceSlotRows) {
    sequenceSlotRows.addEventListener('click', function (event) {
      const target = event.target.closest('[data-sequence-remove-row]');
      if (!target) {
        return;
      }

      const rows = Array.from(sequenceSlotRows.querySelectorAll('[data-sequence-slot-row]'));
      if (rows.length <= 1) {
        return;
      }

      const row = target.closest('[data-sequence-slot-row]');
      if (row) {
        row.remove();
        refreshSequenceRowButtons();
      }
    });
  }

  if (sequenceItemsList) {
    sequenceItemsList.addEventListener('click', function (event) {
      const editButton = event.target.closest('[data-sequence-edit]');
      if (editButton) {
        openSequenceBuilder(parseInt(editButton.dataset.sequenceEdit || '-1', 10));
        return;
      }

      const deleteButton = event.target.closest('[data-sequence-delete]');
      if (deleteButton) {
        const index = parseInt(deleteButton.dataset.sequenceDelete || '-1', 10);
        if (index >= 0) {
          currentSequenceItems.splice(index, 1);
          renderSequenceList();
          closeSequenceBuilder();
          updatePreview();
        }
      }
    });
  }

  wizardStepButtons.forEach((button) => {
    button.addEventListener('click', function () {
      const step = parseInt(button.dataset.stepTarget || '1', 10);
      if (step > 1 && !canContinueFromStep1()) {
        alert('Vul eerst stap 1 in of kies een preset.');
        return;
      }
      setWizardStep(step);
    });
  });

  if (wizardPrevBtn) {
    wizardPrevBtn.addEventListener('click', function () {
      setWizardStep(currentWizardStep - 1);
    });
  }

  if (wizardNextBtn) {
    wizardNextBtn.addEventListener('click', function () {
      if (currentWizardStep === 1 && !canContinueFromStep1()) {
        alert('Vul eerst stap 1 in of kies een preset.');
        return;
      }
      setWizardStep(currentWizardStep + 1);
    });
  }

  presetModeButtons.forEach((button) => {
    button.addEventListener('click', function () {
      const mode = button.dataset.presetMode || '';
      setPresetMode(mode);
    });
  });

  if (cancelPresetModeBtn) {
    cancelPresetModeBtn.addEventListener('click', function () {
      clearPresetMode();
      updatePreview();
    });
  }

  if (testBtn) {
    testBtn.addEventListener('click', function (e) {
      e.preventDefault();

      const finalUrl = buildFinalUrl();
      if (!finalUrl) {
        alert('Geen geldige URL om te testen.');
        return;
      }

      const width = 950;
      const height = 650;
      const left = Math.max(0, Math.round((window.screen.width - width) / 2));
      const top = Math.max(0, Math.round((window.screen.height - height) / 2));

      const features = [
        'width=' + width,
        'height=' + height,
        'left=' + left,
        'top=' + top,
        'resizable=yes',
        'scrollbars=yes'
      ].join(',');

      if (previewWindow && !previewWindow.closed) {
        previewWindow.location.href = finalUrl;
        previewWindow.focus();
      } else {
        previewWindow = window.open(finalUrl, 'kioskPreviewWindow', features);
      }

      if (!previewWindow) {
        alert('Popup geblokkeerd door de browser. Sta popups toe voor deze pagina.');
      }
    });
  }

  if (gearFab) {
    gearFab.addEventListener('click', function () {
      openBeheerModal();
    });
  }

  if (closeBeheerModal) {
    closeBeheerModal.addEventListener('click', function () {
      closeModalNow();
    });
  }

  if (beheerModal) {
    beheerModal.addEventListener('click', function (e) {
      if (e.target === beheerModal) {
        closeModalNow();
      }
    });
  }

  if (presetCommitBtn) {
    presetCommitBtn.addEventListener('click', function () {
      markPresetIntent();
    });
  }

  if (saveBtn) {
    saveBtn.addEventListener('click', function () {
      markSaveIntent();
    });
  }

  if (form) {
    form.addEventListener('submit', function (event) {
      let submitter = resolveSubmitter(event);
      syncHiddenPresetFields();

      if (!submitter && isPresetEditorOpen() && presetCommitBtn) {
        submitter = presetCommitBtn;
      }

      if (isPresetEditorOpen() && intentActionInput && intentActionInput.value === '') {
        markPresetIntent();
      }

      const intent = intentActionInput ? intentActionInput.value : '';

      if (submitter === presetCommitBtn || intent === 'preset_commit') {
        if (!currentPresetMode) {
          event.preventDefault();
          alert('Kies eerst toevoegen, wijzigen of verwijderen.');
          return;
        }

        if ((currentPresetMode === 'preset_update' || currentPresetMode === 'preset_delete') && (!presetSelect || !presetSelect.value)) {
          event.preventDefault();
          alert('Kies eerst een preset.');
          return;
        }

        if (currentPresetMode === 'preset_delete') {
          if (!deleteConfirmArmed) {
            event.preventDefault();
            beginDeleteConfirmation();
            return;
          }

          if (!confirm('Laatste controle: definitief verwijderen?')) {
            event.preventDefault();
            resetDeleteConfirmation();
            return;
          }
        }

        if (presetCommitBtn) {
          markPresetIntent();
          presetCommitBtn.textContent = 'Bezig...';
          presetCommitBtn.disabled = true;
        }
      }

      if (submitter === saveBtn && intent !== 'preset_commit') {
        if (isPresetEditorOpen()) {
          event.preventDefault();
          alert('Sla eerst de preset op via Opslaan in stap 1.');
          return;
        }

        markSaveIntent();
        if (typeSelect) {
          typeSelect.disabled = false;
        }
        saveBtn.textContent = 'Bezig met opslaan...';
        saveBtn.disabled = true;
      }
    });
  }

  document.addEventListener('keydown', function (event) {
    if (!event.altKey) {
      return;
    }

    if (event.key === 'ArrowRight') {
      event.preventDefault();
      if (currentWizardStep < WIZARD_MAX_STEP) {
        setWizardStep(currentWizardStep + 1);
      }
    }

    if (event.key === 'ArrowLeft') {
      event.preventDefault();
      if (currentWizardStep > WIZARD_MIN_STEP) {
        setWizardStep(currentWizardStep - 1);
      }
    }
  });

  let initialWizardStep = 1;
  let shouldStartFreshLanding = false;

  if (form) {
    const lastAction = form.dataset.lastAction || '';
    const hasError = form.dataset.hasError === '1';
    shouldStartFreshLanding = !hasError && lastAction === '';

    if (hasError && lastAction === 'save') {
      initialWizardStep = 4;
    }

    if (hasError && (lastAction === 'preset_add' || lastAction === 'preset_update' || lastAction === 'preset_delete')) {
      setPresetMode(lastAction);
      initialWizardStep = 1;
    }
  }

  initFlashMessages();
  initOnboarding();
  if (shouldStartFreshLanding) {
    resetFreshPresetLanding();
  }
  syncSequenceFromSelectedPreset();
  updatePresetActionAvailability();
  setWizardStep(initialWizardStep);
  updatePreview();

  window.addEventListener('pageshow', function () {
    if (!shouldStartFreshLanding) {
      return;
    }

    resetFreshPresetLanding();
    setWizardStep(1);
    updatePreview();
  });
})();
</script>

</body>
</html>
