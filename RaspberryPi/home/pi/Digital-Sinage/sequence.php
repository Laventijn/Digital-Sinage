<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/kiosk_runtime_helpers.php';

$PRESETS_FILE = '/etc/default/kiosk-presets.json';
$CONFIG_FILE = '/etc/default/kiosk.conf';

function h(?string $value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$defaults = [
    'type' => 'website',
    'url' => 'http://localhost/',
    'kiosk_mode' => 'website',
    'selected_preset_url' => '',
    'sequence_key' => '',
    'resolved_preset_url' => '',
    'sequence_items' => [],
    'sequence_items_defined' => false,
    'timezone' => detectSystemTimezone(),
    'slide_seconds' => 10,
    'slide_start' => true,
    'slide_loop' => true,
    'refresh_seconds' => 30,
    'cache_hours' => 2,
    'on_time' => '',
    'off_time' => '',
];

$presets = loadPresets($PRESETS_FILE);
$config = loadKioskConfig($CONFIG_FILE, $defaults);
$currentPresetState = determineCurrentPresetState($presets, $config);
$selectedPreset = is_array($currentPresetState['preset'] ?? null) ? $currentPresetState['preset'] : null;
$sequenceItems = is_array($selectedPreset) ? resolveConfiguredSequenceItems($config, $selectedPreset) : [];

$error = '';
if (!is_array($selectedPreset)) {
    $error = 'Geen hoofdpreset gekozen.';
}

$runtime = null;
if ($error === '' && is_array($selectedPreset)) {
    if ($sequenceItems !== []) {
        $runtime = resolveSequenceRuntimeData($selectedPreset, $sequenceItems, $presets, $config);
    } else {
        $baseTarget = resolveSelectedPresetTarget($selectedPreset, $config);
        if (!is_array($baseTarget)) {
            $error = 'De hoofdpreset heeft geen geldige URL.';
        } else {
            $runtime = [
                'status' => 'base',
                'active_slot' => null,
                'next_slot' => null,
                'resolved_preset_url' => trim((string)($baseTarget['resolved_preset_url'] ?? '')),
                'runtime_url' => trim((string)($baseTarget['runtime_url'] ?? '')),
                'label' => trim((string)($baseTarget['label'] ?? '')),
                'type' => trim((string)($baseTarget['type'] ?? 'website')),
                'fallback_target' => $baseTarget,
            ];
        }
    }
}

$activeSlotSummary = is_array($runtime)
    ? formatSequenceSlotSummary(is_array($runtime['active_slot'] ?? null) ? $runtime['active_slot'] : null, $presets)
    : '';
$nextSlotSummary = is_array($runtime)
    ? formatSequenceSlotSummary(is_array($runtime['next_slot'] ?? null) ? $runtime['next_slot'] : null, $presets)
    : '';
$statusValue = (string)($runtime['status'] ?? 'missing');
switch ($statusValue) {
    case 'active':
        $statusLabel = 'Actieve override';
        break;
    case 'fallback':
        $statusLabel = 'Hoofdpreset actief';
        break;
    case 'base':
        $statusLabel = 'Geen overrides';
        break;
    default:
        $statusLabel = 'Geen doel gevonden';
        break;
}
?>
<!doctype html>
<html lang="nl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Sequence preview</title>
  <link rel="stylesheet" href="style.css">
  <link rel="stylesheet" href="home.css">
</head>
<body class="page-home">
  <header class="header">
    <div class="title">
      <span class="badge">Kiosk</span>
      <div>
        <h1>Sequence Debug & Preview</h1>
        <div class="small">Controleer de huidige hoofdpreset en tijdelijke overrides.</div>
      </div>
    </div>
  </header>

  <main class="container">
    <?php if ($error !== ''): ?>
      <section class="panel highlight">
        <h2 class="section-title">Sequence</h2>
        <div class="error"><?= h($error) ?></div>
      </section>
    <?php else: ?>
      <section class="panel highlight">
        <h2 class="section-title">Runtime status</h2>

        <div class="hero-grid" style="margin-top:16px;">
          <div class="preview-card">
            <div class="small"><?= h($statusLabel) ?></div>
            <h3 style="margin:8px 0 10px 0;"><?= h((string)($selectedPreset['name'] ?? 'Hoofdpreset')) ?></h3>
            <div><code><?= h((string)($runtime['runtime_url'] ?? 'Geen URL')) ?></code></div>

            <div class="preview-meta">
              <div class="mini">
                <div class="mini-label">Type</div>
                <div class="mini-value"><?= h((string)($runtime['type'] ?? 'website')) ?></div>
              </div>
              <div class="mini">
                <div class="mini-label">Timezone</div>
                <div class="mini-value"><?= h((string)($config['timezone'] ?? detectSystemTimezone())) ?></div>
              </div>
              <div class="mini">
                <div class="mini-label">Overrides</div>
                <div class="mini-value"><?= count($sequenceItems) ?></div>
              </div>
            </div>

            <?php if ($activeSlotSummary !== ''): ?>
              <div class="helper" style="margin-top:12px;">Actieve override: <?= h($activeSlotSummary) ?></div>
            <?php endif; ?>

            <?php if ($nextSlotSummary !== ''): ?>
              <div class="helper">Volgende override: <?= h($nextSlotSummary) ?></div>
            <?php endif; ?>
          </div>

          <div class="summary-card">
            <h3 style="margin:8px 0 10px 0;">Runtime</h3>
            <div class="kv-grid" style="margin-top:12px;">
              <div class="k">Hoofdpreset</div>
              <div class="v"><?= h((string)($selectedPreset['name'] ?? '')) ?></div>

              <div class="k">Hoofdpreset URL</div>
              <div class="v"><?= h((string)($config['selected_preset_url'] ?? '')) ?></div>

              <div class="k">Resolved preset</div>
              <div class="v"><?= h((string)($runtime['resolved_preset_url'] ?? '')) ?></div>

              <div class="k">Watcher modus</div>
              <div class="v"><?= h((string)($config['kiosk_mode'] ?? 'website')) ?></div>
            </div>
          </div>
        </div>
      </section>

      <section class="panel panel-kiosk-builder">
        <h2 class="section-title section-title-main">Geplande overrides</h2>
        <?php if ($sequenceItems === []): ?>
          <div class="helper">Er zijn geen overrides gepland. De hoofdpreset draait dus continu.</div>
        <?php else: ?>
          <div class="sequence-items-list">
            <?php foreach ($sequenceItems as $slot): ?>
              <?php
                $slotPreset = findPresetByUrl($presets, (string)$slot['preset_url']);
                $slotName = is_array($slotPreset)
                    ? trim((string)($slotPreset['name'] ?? $slot['preset_url']))
                    : labelForRuntimeUrl((string)$slot['preset_url']);
              ?>
              <div class="sequence-item-row summary-only">
                <div class="sequence-item-main">
                  <strong class="sequence-item-name"><?= h($slotName) ?></strong>
                  <span class="sequence-item-time"><?= h((string)$slot['start_time']) ?> - <?= h((string)$slot['stop_time']) ?></span>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </section>

      <section class="panel panel-kiosk-builder">
        <h2 class="section-title section-title-main">Hoofdpreset buiten overrides</h2>
        <div class="kiosk-info-grid">
          <div class="kiosk-cell">
            <div class="kiosk-cell-head">Hoofdpreset URL</div>
            <div class="kiosk-cell-body"><code><?= h((string)($selectedPreset['url'] ?? 'Niet ingesteld')) ?></code></div>
          </div>
          <div class="kiosk-cell">
            <div class="kiosk-cell-head">Opmerking</div>
            <div class="kiosk-cell-body">Buiten alle override-tijdsloten tonen we altijd deze hoofdpreset.</div>
          </div>
        </div>
      </section>

      <section class="panel panel-kiosk-builder">
        <h2 class="section-title section-title-main">Preview</h2>
        <?php if (filter_var((string)($runtime['runtime_url'] ?? ''), FILTER_VALIDATE_URL)): ?>
          <iframe
            title="Sequence preview"
            src="<?= h((string)$runtime['runtime_url']) ?>"
            style="width:100%;min-height:560px;border:1px solid rgba(148,163,184,.18);border-radius:24px;background:#0b1220;"
          ></iframe>
          <div class="helper" style="margin-top:12px;">Sommige websites blokkeren iframe-preview. De kiosk zelf gebruikt deze debugpagina niet als runtime, enkel voor controle.</div>
        <?php else: ?>
          <div class="helper">Geen geldige runtime-URL beschikbaar voor preview.</div>
        <?php endif; ?>
      </section>
    <?php endif; ?>
  </main>
</body>
</html>
