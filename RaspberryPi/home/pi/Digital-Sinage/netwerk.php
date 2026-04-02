<?php
// netwerk.php - eenvoudige Wi‑Fi configuratie voor Raspberry Pi (wpa_supplicant)
// Vereist: sudoers-regel voor www-data om /usr/local/sbin/wifi-update.sh zonder wachtwoord uit te voeren.
//   www-data ALL=(ALL) NOPASSWD: /usr/local/sbin/wifi-update.sh

function esc_html($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

// Huidige info
$current_ssid = trim(shell_exec("iwgetid -r 2>/dev/null"));
$current_ip   = trim(shell_exec("hostname -I 2>/dev/null"));
$iface        = 'wlan0';
$country_guess= trim(shell_exec("awk -F= '/^country=/{print $2}' /etc/wpa_supplicant/wpa_supplicant.conf 2>/dev/null"));
if ($country_guess === '') $country_guess = 'BE';

$status_msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action  = $_POST['actie'] ?? '';
    if ($action === 'save') {
        $ssid    = trim($_POST['ssid'] ?? '');
        $psk     = trim($_POST['psk'] ?? '');
        $country = strtoupper(trim($_POST['country'] ?? 'BE'));
        $hidden  = isset($_POST['hidden']) ? '1' : '0';

        if ($ssid === '' || $psk === '') {
            $status_msg = "<p style='color:red;'>SSID en wachtwoord zijn verplicht.</p>";
        } else {
            $cmd = sprintf(
                'sudo /usr/local/sbin/wifi-update.sh %s %s %s %s 2>&1',
                escapeshellarg($country),
                escapeshellarg($ssid),
                escapeshellarg($psk),
                escapeshellarg($hidden)
            );
            $out = shell_exec($cmd);
            $status_msg = "<pre style='background:#eef;padding:10px;border-radius:6px;white-space:pre-wrap'>".esc_html($out)."</pre>";
            // Refresh status
            $current_ssid = trim(shell_exec("iwgetid -r 2>/dev/null"));
            $current_ip   = trim(shell_exec("hostname -I 2>/dev/null"));
        }
    } elseif ($action === 'reconnect') {
        $out = shell_exec('sudo /usr/local/sbin/wifi-update.sh --reconfigure 2>&1');
        $status_msg = "<pre style='background:#eef;padding:10px;border-radius:6px;white-space:pre-wrap'>".esc_html($out)."</pre>";
        $current_ssid = trim(shell_exec("iwgetid -r 2>/dev/null"));
        $current_ip   = trim(shell_exec("hostname -I 2>/dev/null"));
    }
}
?>
<!DOCTYPE html>
<html lang="nl">
<head>
  <meta charset="utf-8">
  <title>Netwerk & Wi‑Fi</title>
  <link rel="stylesheet" href="style.css">
<!--
    body { font-family: Arial, sans-serif; background:#f6f7fb; margin:0; padding:20px; }
    h1 { margin: 0 0 10px; }
    .card { background:#fff; max-width:820px; margin:16px auto; padding:20px; border-radius:10px; box-shadow:0 4px 16px rgba(0,0,0,0.08); }
    label { display:block; font-weight:bold; margin:10px 0 6px; }
    input[type=text], input[type=password], input[type=time], select {
      width:100%; padding:10px; border:1px solid #ccd2e0; border-radius:8px; box-sizing:border-box;
    }
    .row{ display:flex; gap:16px; flex-wrap:wrap; }
    .row > div { flex:1 1 240px; }
    .actions { margin-top:16px; display:flex; gap:10px; flex-wrap:wrap; }
    button { padding:10px 14px; border:none; border-radius:8px; cursor:pointer; }
    .primary { background:#2563eb; color:#fff; }
    .secondary { background:#e5e7eb; }
    .danger { background:#ef4444; color:#fff; }
    code { background:#eef; padding:2px 6px; border-radius:4px; }
    .hint { color:#555; font-size:0.9em; }
  -->
  <script>
    function toggleHidden() {
      const chk = document.getElementById('hidden');
      const help = document.getElementById('hiddenHelp');
      help.style.display = chk.checked ? 'block' : 'none';
    }
  </script>
</head>
<body>
  <div class="card">
    <h1>Wi‑Fi instellingen</h1>
    <p class="hint">Huidige SSID: <code><?php echo esc_html($current_ssid ?: 'niet verbonden'); ?></code> &nbsp;•&nbsp; IP: <code><?php echo esc_html($current_ip ?: 'n/a'); ?></code></p>
    <?php if ($status_msg) echo $status_msg; ?>
    <form method="post">
      <div class="row">
        <div>
          <label for="country">Landcode (regio)</label>
          <input type="text" id="country" name="country" value="<?php echo esc_html($country_guess); ?>" maxlength="2">
          <div class="hint">Gebruik twee hoofdletters, bv. <code>BE</code>, <code>NL</code>, <code>FR</code>.</div>
        </div>
        <div>
          <label for="ssid">SSID (netwerknaam)</label>
          <input type="text" id="ssid" name="ssid" value="" placeholder="Bijv. MijnWiFi">
        </div>
        <div>
          <label for="psk">Wachtwoord</label>
          <input type="password" id="psk" name="psk" value="" placeholder="Wi‑Fi wachtwoord">
        </div>
      </div>

      <div class="row">
        <div>
          <label><input type="checkbox" id="hidden" name="hidden" value="1" onclick="toggleHidden()"> Netwerk verbergen (hidden SSID)</label>
          <div id="hiddenHelp" class="hint" style="display:none;">Als je SSID verborgen is, laat dit aangevinkt. De Pi zal actief proberen te verbinden met een verborgen netwerk.</div>
        </div>
      </div>

      <div class="actions">
        <input type="hidden" name="actie" value="save">
        <button class="primary" type="submit">Opslaan & verbinden</button>
        <button class="secondary" type="submit" name="actie" value="reconnect">Alleen opnieuw verbinden</button>
      </div>
    </form>
    <p class="hint">Let op: bij wijziging van Wi‑Fi kan de verbinding met deze pagina tijdelijk wegvallen.</p>
  </div>

  <div class="card">
    <h2>Tip</h2>
    <p>Om dit te laten werken, voeg in <code>sudo visudo</code> deze regel toe:
      <br><code>www-data ALL=(ALL) NOPASSWD: /usr/local/sbin/wifi-update.sh</code>
    </p>
  </div>
</body>
</html>
