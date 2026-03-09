
<?php
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');
session_start();

/* ========================
   Config: paden & commando's
   ======================== */
$CONFIG_FILE          = '/etc/default/kiosk.conf';
$RESTART_AFTER_SAVE   = true; // true = kioskservice herstarten na "Opslaan", false = niets doen
$CMD_RESTART_KIOSK    = 'sudo systemctl restart kiosk';      // of: 'sudo /home/pi/install/refresh.sh'
$CMD_REBOOT_PI        = 'sudo /bin/systemctl reboot';        // of: 'sudo /sbin/reboot'
$CMD_REFRESH_ONLY     = 'sudo /home/pi/refresh.sh';  // optioneel, laat leeg als je dit niet hebt

/* ========================
   Helpers
   ======================== */
function h(?string $s): string { return htmlspecialchars((string)$s ?? '', ENT_QUOTES, 'UTF-8'); }
function sh(string $cmd): string { return trim((string)shell_exec($cmd)); }

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csrf'];

$defaults = [
    'type'            => 'website',
    'url'             => 'http://localhost/',
    'slide_seconds'   => 10,
    'refresh_minutes' => 60, //welicht se
    'cache_hours'     => 12,
    'on_time'         => '07:00',
    'off_time'        => '21:00',
];
$config = $defaults;

/* ========================
   kiosk.conf inlezen
   ======================== */
if (is_readable($CONFIG_FILE)) {
    foreach (file($CONFIG_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (strpos($line, '=') !== false) {
            [$k, $v] = array_map('trim', explode('=', $line, 2));
            if (array_key_exists($k, $defaults)) $config[$k] = $v;
        }
    }
}

/* ========================
   POST afhandeling
   ======================== */
$notice = $error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'save';
    if (!hash_equals($csrf, $_POST['csrf'] ?? '')) {
        $error = 'Ongeldige sessie (CSRF). Probeer opnieuw.';
    } else {
        if ($action === 'save') {
            // Validatie
            $type            = in_array($_POST['type'] ?? '', ['website','presentation'], true) ? $_POST['type'] : 'website';
            $url             = trim($_POST['url'] ?? '');
            $slide_seconds   = (int)($_POST['slide_seconds']   ?? 10);
            $refresh_minutes = (int)($_POST['refresh_minutes'] ?? 15);
            $cache_hours     = (int)($_POST['cache_hours']     ?? 12);
            $on_time         = preg_replace('/[^0-9:]/','', $_POST['on_time']  ?? '07:00');
            $off_time        = preg_replace('/[^0-9:]/','', $_POST['off_time'] ?? '21:00');

            if (!filter_var($url, FILTER_VALIDATE_URL)) {
                $error = 'Ongeldige URL';
            } elseif ($slide_seconds < 1 || $slide_seconds > 3600) {
                $error = 'Presentatie timing moet tussen 1 en 3600 s liggen.';
            } elseif ($refresh_minutes < 0 || $refresh_minutes > 1440) {
                $error = 'Ververs-interval moet tussen 0 en 1440 min liggen.';
            } elseif ($cache_hours < 0 || $cache_hours > 168) {
                $error = 'Cache legen moet tussen 0 en 168 uur liggen.';
            } elseif (!preg_match('/^\d{2}:\d{2}$/', $on_time) || !preg_match('/^\d{2}:\d{2}$/', $off_time)) {
                $error = 'Tijden moeten UU:MM zijn.';
            }

            if (!$error) {
                $config = compact('type','url','slide_seconds','refresh_minutes','cache_hours','on_time','off_time');
                $lines = [];
$lines[] = "KioskURL=$url";
$lines[] = "RefreshTime=$refresh_minutes";
$lines[] = "CacheInterval=$cache_hours";
$lines[] = "StartTime=$on_time";
$lines[] = "StopTime=$off_time";;
                foreach ($config as $k=>$v) $lines[] = "$k=$v";
                if (@file_put_contents($CONFIG_FILE, implode(PHP_EOL,$lines).PHP_EOL) === false) {
                    $error = "Kon $CONFIG_FILE niet schrijven. Controleer permissies.";
                } else {
                    if ($RESTART_AFTER_SAVE && $CMD_RESTART_KIOSK) {
                        shell_exec($CMD_RESTART_KIOSK . ' > /dev/null 2>&1 &');
                        $notice = 'Configuratie opgeslagen en kiosk herstart.';
                    } else {
                        $notice = 'Configuratie opgeslagen.';
                    }
                }
            }
        }
        elseif ($action === 'restart_kiosk') {
            if ($CMD_RESTART_KIOSK) {
                shell_exec($CMD_RESTART_KIOSK . ' > /dev/null 2>&1 &');
                $notice = 'Kioskservice wordt herstart…';
            } else {
                $error = 'Herstart-commando voor kiosk is niet geconfigureerd.';
            }
        }
        elseif ($action === 'refresh') {
            if ($CMD_REFRESH_ONLY) {
                shell_exec($CMD_REFRESH_ONLY . ' > /dev/null 2>&1 &');
                $notice = 'Pagina wordt ververst…';
            } else {
                $error = 'Refresh-script is niet geconfigureerd.';
            }
        }
        elseif ($action === 'reboot') {
            if ($CMD_REBOOT_PI) {
                // Asynchroon rebooten zodat de response nog kan tonen dat de reboot start
                shell_exec($CMD_REBOOT_PI . ' > /dev/null 2>&1 &');
                $notice = 'Reboot gestart… De Pi zal binnen enkele seconden opnieuw opstarten.';
            } else {
                $error = 'Reboot-commando is niet geconfigureerd.';
            }
        }
    }
}

/* ========================
   Systeeminfo (ter info)
   ======================== */
$piModel  = @file_exists('/proc/device-tree/model') ? trim(file_get_contents('/proc/device-tree/model')) : '';
$osPretty = sh("grep PRETTY_NAME /etc/os-release | cut -d= -f2 | tr -d '\"'");
$arch     = php_uname('m');
$apache   = sh('apache2 -v | head -n1');
$chromium = sh('chromium-browser --version 2>/dev/null || chromium --version 2>/dev/null');
$phpv     = PHP_VERSION;
$uptime   = sh('uptime -p 2>/dev/null');
$ip       = sh("hostname -I | cut -d' ' -f1");
$ip_eth0  = sh("ip -4 addr show eth0 | awk '/inet / {print $2}' | cut -d/ -f1");
$ip_wlan0 = sh("ip -4 addr show wlan0 | awk '/inet / {print $2}' | cut -d/ -f1");

?>
<!doctype html>
<html lang="nl">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Kiosk Configuratie</title>
<link rel="stylesheet" href="style.css">

</head>
<body>
<header class="header"><div class="title"><span class="badge">Kiosk</span><h1>Configuratie</h1></div></header>
<main class="container">
 <div class="panel" style="margin-top:0px">
    <h2 class="section-title">Netwerkinformatie</h2>
    
      <div>
      <strong>Ethernet IP: </strong> <?= h($ip_eth0 ?: 'Onbekend') ?><br>
      <strong>Wlan IP: </strong> <?= h($ip_wlan0 ?: 'Onbekend') ?>
      </div>
       <div class="actions">
          <a class="ghost" href="./netwerk.php" target="_blank" rel="noreferrer noopener">Wifi Instellen</a>
        </div>
    
  </div>

  <div class="panel" style="margin-top:16px">
    <p class="small" style="margin-top:0">Opslaan verandert <em>kiosk.conf</em>. Standaard wordt daarna alleen de <strong>kioskservice</strong> herstart. De knop <strong>Reboot Pi</strong> voert een volledige herstart uit.</p>

    <?php if ($notice): ?><div class="notice"><?=h($notice)?></div><?php endif; ?>
    <?php if ($error): ?><div class="error"><?=h($error)?></div><?php endif; ?>

    <form method="post" novalidate>
      <input type="hidden" name="csrf" value="<?=h($csrf)?>">
      <input type="hidden" name="action" value="save">

      <h2 class="section-title">Bron</h2>
      <div class="form-row">
        <div>
          <label><input type="radio" name="type" value="website" <?= $config['type']==='website'?'checked':''; ?>> Website</label>
          &nbsp;&nbsp;
          <label><input type="radio" name="type" value="presentation" <?= $config['type']==='presentation'?'checked':''; ?>> Google Presentatie</label>
        </div>

        <label for="url">URL</label>
        <input id="url" name="url" type="url" required placeholder="https://…" value="<?=h($config['url'])?>">
        <div class="actions">
          <a class="ghost" id="testUrl" href="#" target="_blank" rel="noreferrer noopener">Test URL</a>
        </div>
      </div>

      <hr class="sep">
      <h2 class="section-title">Tijden & Intervallen</h2>
      <div class="grid">
        <div class="form-row">
          <label for="slide_seconds">Presentatie timing (s)</label>
          <input id="slide_seconds" name="slide_seconds" type="number" min="1" max="3600" value="<?= (int)$config['slide_seconds'] ?>">
          <div class="helper">Alleen relevant voor Google Presentaties.</div>
        </div>
        <div class="form-row">
          <label for="refresh_minutes">Ververs elke (min)</label>
          <input id="refresh_minutes" name="refresh_minutes" type="number" min="0" max="1440" value="<?= (int)$config['refresh_minutes'] ?>">
        </div>
        <div class="form-row">
          <label for="cache_hours">Cache legen om de hoeveel uur</label>
          <input id="cache_hours" name="cache_hours" type="number" min="0" max="168" value="<?= (int)$config['cache_hours'] ?>">
        </div>
        <div class="form-row">
          <label for="on_time">Tijdstip Pi aan (UU:MM)</label>
          <input id="on_time" name="on_time" type="time" value="<?=h($config['on_time'])?>">
        </div>
        <div class="form-row">
          <label for="off_time">Tijdstip Pi uit (UU:MM)</label>
          <input id="off_time" name="off_time" type="time" value="<?=h($config['off_time'])?>">
        </div>
      </div>

      <div class="actions">
        <button type="submit">Opslaan</button>
        <?php if (!empty($CMD_RESTART_KIOSK)): ?>
          <button type="submit" name="action" value="restart_kiosk" class="ghost">Herstart kiosk</button>
        <?php endif; ?>
        <?php if (!empty($CMD_REFRESH_ONLY)): ?>
          <button type="submit" name="action" value="refresh" class="ghost">Refresh</button>
        <?php endif; ?>
        <?php if (!empty($CMD_REBOOT_PI)): ?>
          <button type="submit" name="action" value="reboot" class="danger" id="btnReboot">Reboot Pi</button>
        <?php endif; ?>
      </div>
    </form>
  </div>

  <div class="panel" style="margin-top:16px">
    <h2 class="section-title">Systeeminformatie</h2>
    <div class="sysgrid">
      <div><strong>Raspberry Pi</strong><br><?= h($piModel ?: 'Onbekend') ?></div>
      <div><strong>OS</strong><br><?= h($osPretty ?: php_uname('s')) ?> (<?= h($arch) ?>)</div>
      <div><strong>Apache</strong><br><?= h($apache ?: 'apache2 niet gevonden') ?></div>
      <div><strong>Chromium</strong><br><?= h($chromium ?: 'Chromium niet gevonden') ?></div>
      <div><strong>PHP</strong><br><?= h($phpv) ?></div>
      <div><strong>Uptime</strong><br><?= h($uptime ?: 'n.v.t.') ?></div>
    </div>
    <hr class="sep">
    <div class="small">© Valentijn Rombaut 2025</div>
  </div>
</main>

<script>
(function(){
  const typeRadios = document.querySelectorAll('input[name="type"]');
  const slide = document.getElementById('slide_seconds');
  function update(){
    const isPres = [...typeRadios].find(r=>r.checked)?.value === 'presentation';
    slide.disabled = !isPres;
    slide.closest('.form-row').style.opacity = isPres ? 1 : .6;
  }
  typeRadios.forEach(r=>r.addEventListener('change', update)); update();

  const url = document.getElementById('url');
  const test = document.getElementById('testUrl');
  function ensureHttp(u){ if (!u) return ''; if (!/^https?:\\/\\//i.test(u)) return 'https://'+u; return u; }
  test.addEventListener('click', e => { e.preventDefault(); const u=ensureHttp(url.value.trim()); if(u) window.open(u,'_blank','noopener,noreferrer'); });

  const btnReboot = document.getElementById('btnReboot');
  if (btnReboot) btnReboot.addEventListener('click', function(e){
    if (!confirm('Zeker weten? De Raspberry Pi gaat NU herstarten.')) e.preventDefault();
  });
})();
</script>
</body>
</html>