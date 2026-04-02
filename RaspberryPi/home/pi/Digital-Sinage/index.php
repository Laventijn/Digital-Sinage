<?php
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');
session_start();

$CONFIG_FILE        = '/etc/default/kiosk.conf';
$RESTART_AFTER_SAVE = true;

$CMD_RESTART_KIOSK  = 'sudo systemctl restart kiosk.service';
$CMD_REBOOT_PI      = 'sudo /bin/systemctl reboot';
$CMD_REFRESH_ONLY   = 'sudo -u pi /home/pi/refresh_once.sh';
$CMD_SSH_START      = 'sudo systemctl start ssh';
$CMD_SSH_STOP       = 'sudo systemctl stop ssh';

$PRESETS = [
    '' => '-- Kies een preset --',
    'http://localhost/' => 'Lokale startpagina',
    'https://www.google.com' => 'Google',
    'https://www.wikipedia.org' => 'Wikipedia',
    'https://docs.google.com/presentation/d/1yNNm-DEGqmV7z9VB2xw8Ers40Dfq6Nk0YDA37EQFDW0/edit?slide=id.p#slide=id.p' => 'Mijn Google Presentatie',
];

function h(?string $s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function sh(string $cmd): string {
    return trim((string)shell_exec($cmd));
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

function looksLikeGoogleSlides(string $url): bool {
    return (bool)preg_match('~^https?://docs\.google\.com/presentation/d/[a-zA-Z0-9_-]+~i', trim($url));
}

function buildGoogleSlidesPresentUrl(string $url, bool $slideStart, bool $slideLoop, int $slideDelayMs): string {
    $url = trim($url);

    if (preg_match('~^https?://docs\.google\.com/presentation/d/([a-zA-Z0-9_-]+)~i', $url, $m)) {
        $presentationId = $m[1];
        $params = [
            'start'   => $slideStart ? 'true' : 'false',
            'loop'    => $slideLoop ? 'true' : 'false',
            'delayms' => (string)$slideDelayMs,
        ];
        return 'https://docs.google.com/presentation/d/' . $presentationId . '/present?' . http_build_query($params);
    }

    $url = preg_replace('/([?&])(start|loop|delayms)=[^&]*/i', '', $url);
    $url = preg_replace('/[?&]+$/', '', $url);
    $separator = (strpos($url, '?') === false) ? '?' : '&';

    return $url
        . $separator
        . 'start=' . ($slideStart ? 'true' : 'false')
        . '&loop=' . ($slideLoop ? 'true' : 'false')
        . '&delayms=' . $slideDelayMs;
}

function normalizeContentUrl(string $type, string $url, bool $slideStart, bool $slideLoop, int $slideSeconds): string {
    $url = trim($url);
    if ($type === 'presentation') {
        return buildGoogleSlidesPresentUrl($url, $slideStart, $slideLoop, $slideSeconds * 1000);
    }
    return $url;
}

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csrf'];

$defaults = [
    'type'            => 'website',
    'url'             => 'http://localhost/',
    'slide_seconds'   => 10,
    'slide_start'     => true,
    'slide_loop'      => true,
    'refresh_seconds' => 30,
    'cache_hours'     => 2,
    'on_time'         => '',
    'off_time'        => '',
];

$config = $defaults;

if (is_readable($CONFIG_FILE)) {
    foreach (file($CONFIG_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);

        if ($line === '' || str_starts_with($line, '#') || strpos($line, '=') === false) {
            continue;
        }

        [$k, $v] = array_map('trim', explode('=', $line, 2));

        switch ($k) {
            case 'KioskURL':
                $config['url'] = $v;

                if (looksLikeGoogleSlides($v)) {
                    $config['type'] = 'presentation';
                    $parts = parse_url($v);

                    if (!empty($parts['query'])) {
                        parse_str($parts['query'], $query);

                        if (isset($query['start'])) {
                            $config['slide_start'] = stringToBool((string)$query['start'], true);
                        }

                        if (isset($query['loop'])) {
                            $config['slide_loop'] = stringToBool((string)$query['loop'], true);
                        }

                        if (isset($query['delayms']) && ctype_digit((string)$query['delayms'])) {
                            $config['slide_seconds'] = max(1, (int)$query['delayms'] / 1000);
                        }
                    }
                }
                break;

            case 'SlideStart':
                $config['slide_start'] = stringToBool($v, true);
                break;

            case 'SlideLoop':
                $config['slide_loop'] = stringToBool($v, true);
                break;

            case 'SlideDelay':
                if (is_numeric($v)) {
                    $config['slide_seconds'] = max(1, (int)$v / 1000);
                }
                break;

            case 'RefreshTime':
                if (is_numeric($v)) {
                    $config['refresh_seconds'] = (int)$v;
                }
                break;

            case 'CacheInterval':
                if (is_numeric($v)) {
                    $config['cache_hours'] = (int)$v;
                }
                break;

            case 'StartTime':
                $config['on_time'] = $v;
                break;

            case 'StopTime':
                $config['off_time'] = $v;
                break;
        }
    }
}

$notice = '';
$error  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'save';

    if (!hash_equals($csrf, $_POST['csrf'] ?? '')) {
        $error = '❌ Ongeldige sessie (CSRF). Herlaad de pagina en probeer opnieuw.';
    } else {
        if ($action === 'save') {
            $type            = in_array($_POST['type'] ?? '', ['website', 'presentation'], true) ? $_POST['type'] : 'website';
            $url             = trim($_POST['url'] ?? '');
            $preset_url      = trim($_POST['preset_url'] ?? '');
            $slide_seconds   = (int)($_POST['slide_seconds'] ?? 10);
            $slide_start     = isset($_POST['slide_start']);
            $slide_loop      = isset($_POST['slide_loop']);
            $refresh_seconds = (int)($_POST['refresh_seconds'] ?? 30);
            $cache_hours     = (int)($_POST['cache_hours'] ?? 2);
            $on_time         = preg_replace('/[^0-9:]/', '', $_POST['on_time'] ?? '');
            $off_time        = preg_replace('/[^0-9:]/', '', $_POST['off_time'] ?? '');

            if ($preset_url !== '') {
                $url = $preset_url;
            }

            if (looksLikeGoogleSlides($url)) {
                $type = 'presentation';
            }

            if (!filter_var($url, FILTER_VALIDATE_URL)) {
                $error = '❌ Ongeldige URL. Vul een volledige URL in, bijvoorbeeld: https://example.com';
            } elseif ($slide_seconds < 1 || $slide_seconds > 3600) {
                $error = '❌ Presentatie timing moet tussen 1 en 3600 seconden liggen.';
            } elseif ($refresh_seconds < 0 || $refresh_seconds > 86400) {
                $error = '❌ Refresh tijd moet tussen 0 en 86400 seconden liggen.';
            } elseif ($cache_hours < 0 || $cache_hours > 168) {
                $error = '❌ Cache interval moet tussen 0 en 168 uur liggen.';
            } elseif (($on_time !== '' && !preg_match('/^\d{2}:\d{2}$/', $on_time)) ||
                      ($off_time !== '' && !preg_match('/^\d{2}:\d{2}$/', $off_time))) {
                $error = '❌ Start- en stoptijd moeten in formaat UU:MM staan.';
            }

            if (!$error) {
                $finalUrl = normalizeContentUrl($type, $url, $slide_start, $slide_loop, $slide_seconds);

                $config = [
                    'type'            => $type,
                    'url'             => $finalUrl,
                    'slide_seconds'   => $slide_seconds,
                    'slide_start'     => $slide_start,
                    'slide_loop'      => $slide_loop,
                    'refresh_seconds' => $refresh_seconds,
                    'cache_hours'     => $cache_hours,
                    'on_time'         => $on_time,
                    'off_time'        => $off_time,
                ];

                $lines   = [];
                $lines[] = '# ==========================================';
                $lines[] = '# Kiosk configuratiebestand';
                $lines[] = '# ==========================================';
                $lines[] = '';
                $lines[] = '# Basis URL';
                $lines[] = 'KioskURL=' . $config['url'];
                $lines[] = '';
                $lines[] = '# Google Slides opties';
                $lines[] = 'SlideStart=' . boolToString($config['slide_start']);
                $lines[] = 'SlideLoop=' . boolToString($config['slide_loop']);
                $lines[] = 'SlideDelay=' . ((int)$config['slide_seconds'] * 1000);
                $lines[] = '';
                $lines[] = '# Refresh instellingen';
                $lines[] = 'RefreshTime=' . $config['refresh_seconds'];
                $lines[] = 'CacheInterval=' . $config['cache_hours'];
                $lines[] = '';
                $lines[] = '# Tijdschema (optioneel)';
                $lines[] = $config['on_time'] !== '' ? 'StartTime=' . $config['on_time'] : '#StartTime=08:00';
                $lines[] = $config['off_time'] !== '' ? 'StopTime=' . $config['off_time'] : '#StopTime=18:00';

                if (@file_put_contents($CONFIG_FILE, implode(PHP_EOL, $lines) . PHP_EOL) === false) {
                    $error = "❌ Kon {$CONFIG_FILE} niet schrijven. Controleer permissies.";
                } else {
                    if ($RESTART_AFTER_SAVE) {
                        shell_exec($CMD_RESTART_KIOSK . ' > /dev/null 2>&1 &');
                        $notice = '✅ Configuratie opgeslagen en kioskservice herstart.';
                    } else {
                        $notice = '✅ Configuratie opgeslagen.';
                    }
                }
            }
        } elseif ($action === 'restart_kiosk') {
            shell_exec($CMD_RESTART_KIOSK . ' > /dev/null 2>&1 &');
            $notice = '✅ Kioskservice wordt herstart...';
        } elseif ($action === 'refresh') {
            shell_exec($CMD_REFRESH_ONLY . ' > /dev/null 2>&1 &');
            $notice = '✅ Refresh-script gestart...';
        } elseif ($action === 'reboot') {
            shell_exec($CMD_REBOOT_PI . ' > /dev/null 2>&1 &');
            $notice = '✅ Reboot gestart...';
        } elseif ($action === 'ssh_start') {
            shell_exec($CMD_SSH_START . ' > /dev/null 2>&1 &');
            $notice = '✅ SSH gestart. VS Code Remote SSH kan nu verbinden.';
        } elseif ($action === 'ssh_stop') {
            shell_exec($CMD_SSH_STOP . ' > /dev/null 2>&1 &');
            $notice = '✅ SSH gestopt. VS Code Remote SSH is nu geblokkeerd.';
        }
    }
}

$previewUrl = normalizeContentUrl(
    $config['type'],
    $config['url'],
    (bool)$config['slide_start'],
    (bool)$config['slide_loop'],
    (int)$config['slide_seconds']
);

$currentTypeLabel = $config['type'] === 'presentation' ? 'Google Presentatie' : 'Website';
$currentModeText  = $config['type'] === 'presentation' ? '🎞️ Presentatiemodus actief' : '🌍 Websitemodus actief';

$piModel        = @file_exists('/proc/device-tree/model') ? trim(file_get_contents('/proc/device-tree/model')) : '';
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
?>
<!doctype html>
<html lang="nl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Kiosk Configuratie</title>
  <link rel="stylesheet" href="style.css">
</head>
<body>

<header class="header">
  <div class="title">
    <span class="badge">Kiosk</span>
    <div>
      <h1>🖥️ Kiosk Configuratie Dashboard</h1>
      <div class="small">Beheer je Raspberry Pi signage systeem op één plek</div>
    </div>
  </div>
</header>

<main class="container">
  <?php if ($notice): ?>
    <div class="notice"><?= h($notice) ?></div>
  <?php endif; ?>

  <?php if ($error): ?>
    <div class="error"><?= h($error) ?></div>
  <?php endif; ?>

  <section class="panel highlight">
    <h2 class="section-title">📊 Echt Dashboard</h2>

    <div class="dashboard-grid">
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

    <div class="hero-grid" style="margin-top:16px;">
      <div class="preview-card">
        <div class="small"><?= h($currentModeText) ?></div>
        <h3 style="margin:8px 0 10px 0;">Wat wordt nu getoond</h3>
        <div><code id="previewUrlTop"><?= h($previewUrl) ?></code></div>

        <div class="preview-meta">
          <div class="mini">
            <div class="mini-label">Type</div>
            <div class="mini-value"><?= h($currentTypeLabel) ?></div>
          </div>
          <div class="mini">
            <div class="mini-label">Refresh</div>
            <div class="mini-value"><?= (int)$config['refresh_seconds'] ?> sec</div>
          </div>
          <div class="mini">
            <div class="mini-label">Slides</div>
            <div class="mini-value"><?= (int)$config['slide_seconds'] ?> sec</div>
          </div>
        </div>
      </div>

      <div class="summary-card">
        <div class="small">Snelle samenvatting</div>
        <div class="kv-grid" style="margin-top:12px;">
          <div class="k">Loop</div>
          <div class="v"><?= $config['slide_loop'] ? 'Ja' : 'Nee' ?></div>

          <div class="k">Start automatisch</div>
          <div class="v"><?= $config['slide_start'] ? 'Ja' : 'Nee' ?></div>

          <div class="k">Cache legen</div>
          <div class="v"><?= (int)$config['cache_hours'] ?> uur</div>

          <div class="k">Pi aan</div>
          <div class="v"><?= h($config['on_time'] !== '' ? $config['on_time'] : 'Niet ingesteld') ?></div>

          <div class="k">Pi uit</div>
          <div class="v"><?= h($config['off_time'] !== '' ? $config['off_time'] : 'Niet ingesteld') ?></div>
        </div>
      </div>
    </div>
  </section>

  <section class="panel">
    <h2 class="section-title">🌐 Netwerk</h2>

    <div class="info-grid">
      <div class="info-item">
        <div class="info-label">Hostname</div>
        <div class="info-value"><?= h($hostName ?: 'Onbekend') ?></div>
      </div>

      <div class="info-item">
        <div class="info-label">Ethernet</div>
        <div class="info-value"><?= h($ip_eth0 ?: 'Geen ethernet verbinding') ?></div>
      </div>

      <div class="info-item">
        <div class="info-label">Wi-Fi</div>
        <div class="info-value"><?= h($ip_wlan0 ?: 'Geen Wi-Fi verbinding') ?></div>
      </div>

      <div class="info-item">
        <div class="info-label">SSH status</div>
        <div class="info-value"><span class="status-pill <?= h($sshBadgeClass) ?>"><?= h($sshBadgeText) ?></span></div>
      </div>
    </div>

    <div class="actions">
      <a class="ghost" href="./netwerk.php" target="_blank" rel="noreferrer noopener">📶 Wi-Fi instellen</a>
    </div>
  </section>

  <section class="panel">
    <h2 class="section-title">⚙️ Configuratie</h2>
    <p class="small" style="margin-top:0">Hier kies je welke inhoud in kioskmodus wordt getoond. Na opslaan wordt de kioskservice automatisch herstart.</p>

    <form method="post" novalidate id="configForm">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <input type="hidden" name="action" value="save">

      <div class="save-bar">
        <div>
          <div style="font-weight:800; font-size:18px;">💾 Configuratie opslaan</div>
          <div class="small">Sla je instellingen op en pas ze direct toe op de Raspberry Pi.</div>
        </div>
        <button type="submit" id="saveBtn" class="save-main">💾 Opslaan en toepassen</button>
      </div>

      <hr class="sep">

      <div class="subsection">
        <h3 class="subsection-title">🌍 Bron</h3>

        <div class="grid">
          <div class="form-row">
            <label for="preset_url">📋 Preset</label>
            <select id="preset_url" name="preset_url">
              <?php foreach ($PRESETS as $presetValue => $presetLabel): ?>
                <option value="<?= h($presetValue) ?>"><?= h($presetLabel) ?></option>
              <?php endforeach; ?>
            </select>
            <div class="helper">Kies snel een vooraf ingestelde website of presentatie.</div>
          </div>

          <div class="form-row">
            <label for="url">🌐 URL</label>
            <input
              id="url"
              name="url"
              type="url"
              required
              placeholder="https://voorbeeld.be"
              value="<?= h($config['url']) ?>"
            >
            <div class="helper">Vul een volledige URL in. Google Slides wordt automatisch herkend.</div>
          </div>
        </div>

        <div class="radio-group">
          <label class="radio-card">
            <input type="radio" name="type" value="website" <?= $config['type'] === 'website' ? 'checked' : '' ?>>
            <span>Website</span>
          </label>

          <label class="radio-card">
            <input type="radio" name="type" value="presentation" <?= $config['type'] === 'presentation' ? 'checked' : '' ?>>
            <span>Google Presentatie</span>
          </label>
        </div>

        <div class="current-url">
          <div class="small">🔗 Uiteindelijke kiosk-URL</div>
          <div><code id="previewUrl"><?= h($previewUrl) ?></code></div>
        </div>

        <div class="actions">
          <a class="ghost test-btn" id="testUrl" href="#" rel="noreferrer noopener">🔎 Test eind-URL</a>
          <button type="button" class="ghost close-test-btn" id="closeTestWindow">❌ Sluit testvenster</button>
        </div>
      </div>

      <hr class="sep">

      <div class="subsection">
        <h3 class="subsection-title">⏱️ Tijden & Intervallen</h3>

        <div class="grid">
          <div class="form-row" id="slideRow">
            <label for="slide_seconds">Presentatie timing (seconden)</label>
            <input id="slide_seconds" name="slide_seconds" type="number" min="1" max="3600" value="<?= (int)$config['slide_seconds'] ?>">
            <div class="helper">Alleen relevant voor Google Presentaties.</div>
          </div>

          <div class="form-row">
            <label for="refresh_seconds">Automatisch verversen (seconden)</label>
            <input id="refresh_seconds" name="refresh_seconds" type="number" min="0" max="86400" value="<?= (int)$config['refresh_seconds'] ?>">
            <div class="helper">0 = niet automatisch verversen.</div>
          </div>

          <div class="form-row" id="slideStartRow">
            <label class="checkbox-row">
              <input type="checkbox" id="slide_start" name="slide_start" <?= $config['slide_start'] ? 'checked' : '' ?>>
              <span>Start automatisch</span>
            </label>
            <div class="helper">Voegt <code>start=true</code> toe aan de presentatielink.</div>
          </div>

          <div class="form-row" id="slideLoopRow">
            <label class="checkbox-row">
              <input type="checkbox" id="slide_loop" name="slide_loop" <?= $config['slide_loop'] ? 'checked' : '' ?>>
              <span>Loop presentatie</span>
            </label>
            <div class="helper">Voegt <code>loop=true</code> toe aan de presentatielink.</div>
          </div>

          <div class="form-row">
            <label for="cache_hours">Cache legen om de hoeveel uur</label>
            <input id="cache_hours" name="cache_hours" type="number" min="0" max="168" value="<?= (int)$config['cache_hours'] ?>">
            <div class="helper">0 = niet automatisch cache legen.</div>
          </div>

          <div class="form-row">
            <label for="on_time">Pi aan om (UU:MM)</label>
            <input id="on_time" name="on_time" type="time" value="<?= h($config['on_time']) ?>">
            <div class="helper">Laat leeg als je geen aan-tijd wilt instellen.</div>
          </div>

          <div class="form-row">
            <label for="off_time">Pi uit om (UU:MM)</label>
            <input id="off_time" name="off_time" type="time" value="<?= h($config['off_time']) ?>">
            <div class="helper">Laat leeg als je geen uit-tijd wilt instellen.</div>
          </div>
        </div>
      </div>

      <hr class="sep">

      <div class="subsection">
        <h3 class="subsection-title">🛠️ Acties</h3>

        <div class="group-grid">
          <div class="action-box">
            <h4>Kiosk acties</h4>
            <div class="actions">
              <button type="submit" name="action" value="restart_kiosk" class="ghost">🔁 Herstart kiosk</button>
              <button type="submit" name="action" value="refresh" class="ghost">♻️ Eenmalige refresh</button>
            </div>
          </div>

          <div class="action-box">
            <h4>Remote beheer</h4>
            <div class="actions">
              <button type="submit" name="action" value="ssh_start" class="ghost">🔓 VS Code connectie openen</button>
              <button type="submit" name="action" value="ssh_stop" class="ghost">🔒 VS Code connectie sluiten</button>
            </div>
          </div>

          <div class="action-box danger-zone" style="grid-column: 1 / -1;">
            <h4>⚠️ Gevaarlijke actie</h4>
            <div class="small">Gebruik reboot alleen als het echt nodig is.</div>
            <div class="actions">
              <button type="submit" name="action" value="reboot" class="danger" id="btnReboot">⚠️ Reboot Pi</button>
            </div>
          </div>
        </div>
      </div>
    </form>
  </section>

  <section class="panel">
    <h2 class="section-title">🖥️ Systeeminformatie</h2>

    <div class="sysgrid">
      <div class="syscard"><strong>Raspberry Pi</strong><br><?= h($piModel ?: 'Onbekend') ?></div>
      <div class="syscard"><strong>Besturingssysteem</strong><br><?= h($osPretty ?: php_uname('s')) ?> (<?= h($arch) ?>)</div>
      <div class="syscard"><strong>Apache</strong><br><?= h($apache ?: 'apache2 niet gevonden') ?></div>
      <div class="syscard"><strong>Chromium</strong><br><?= h($chromium ?: 'Chromium niet gevonden') ?></div>
      <div class="syscard"><strong>PHP</strong><br><?= h($phpv) ?></div>
      <div class="syscard"><strong>Uptime</strong><br><?= h($uptime ?: 'n.v.t.') ?></div>
    </div>

    <div class="footer-note small">© Valentijn Rombaut 2025</div>
  </section>
</main>

<script>
(function () {
  const typeRadios = document.querySelectorAll('input[name="type"]');
  const urlInput = document.getElementById('url');
  const slideInput = document.getElementById('slide_seconds');
  const slideStartInput = document.getElementById('slide_start');
  const slideLoopInput = document.getElementById('slide_loop');
  const slideRow = document.getElementById('slideRow');
  const slideStartRow = document.getElementById('slideStartRow');
  const slideLoopRow = document.getElementById('slideLoopRow');
  const testBtn = document.getElementById('testUrl');
  const closeTestWindowBtn = document.getElementById('closeTestWindow');
  const previewUrlEl = document.getElementById('previewUrl');
  const previewUrlTopEl = document.getElementById('previewUrlTop');
  const presetSelect = document.getElementById('preset_url');
  const form = document.getElementById('configForm');
  const saveBtn = document.getElementById('saveBtn');
  const btnReboot = document.getElementById('btnReboot');

  let previewWindow = null;

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
    const selected = [...typeRadios].find(r => r.checked);
    return selected ? selected.value : 'website';
  }

  function setType(type) {
    const target = document.querySelector('input[name="type"][value="' + type + '"]');
    if (target) {
      target.checked = true;
    }
  }

  function buildFinalUrl() {
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

    slideRow.style.opacity = isPresentation ? '1' : '.55';
    slideStartRow.style.opacity = isPresentation ? '1' : '.55';
    slideLoopRow.style.opacity = isPresentation ? '1' : '.55';
  }

  function autoDetectType() {
    const url = ensureHttp(urlInput.value.trim());
    if (isGoogleSlidesUrl(url)) {
      setType('presentation');
    }
  }

  function updatePreview() {
    autoDetectType();
    updatePresentationFields();
    const finalUrl = buildFinalUrl();
    const text = finalUrl || 'Nog geen geldige URL ingevuld';
    previewUrlEl.textContent = text;
    if (previewUrlTopEl) {
      previewUrlTopEl.textContent = text;
    }
  }

  typeRadios.forEach(radio => {
    radio.addEventListener('change', updatePreview);
  });

  [urlInput, slideInput, slideStartInput, slideLoopInput].forEach(el => {
    if (el) {
      el.addEventListener('input', updatePreview);
      el.addEventListener('change', updatePreview);
    }
  });

  if (presetSelect) {
    presetSelect.addEventListener('change', function () {
      if (presetSelect.value) {
        urlInput.value = presetSelect.value;
        autoDetectType();
        updatePreview();
      }
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

  if (closeTestWindowBtn) {
    closeTestWindowBtn.addEventListener('click', function () {
      if (previewWindow && !previewWindow.closed) {
        previewWindow.close();
        previewWindow = null;
      } else {
        alert('Er is geen testvenster open.');
      }
    });
  }

  if (btnReboot) {
    btnReboot.addEventListener('click', function (e) {
      if (!confirm('Zeker weten? De Raspberry Pi gaat nu herstarten.')) {
        e.preventDefault();
      }
    });
  }

  if (form && saveBtn) {
    form.addEventListener('submit', function () {
      saveBtn.textContent = '⏳ Bezig met opslaan...';
      saveBtn.disabled = true;
    });
  }

  updatePreview();
})();
</script>
</body>
</html>