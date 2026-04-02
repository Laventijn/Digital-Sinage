<?php
declare(strict_types=1);

function looksLikeGoogleSlides(string $url): bool {
    return (bool)preg_match('~^https?://docs\.google\.com/presentation/d/[a-zA-Z0-9_-]+~i', trim($url));
}

function buildGoogleSlidesPresentUrl(string $url, bool $slideStart, bool $slideLoop, int $slideDelayMs): string {
    $url = trim($url);

    if (preg_match('~^https?://docs\.google\.com/presentation/d/([a-zA-Z0-9_-]+)~i', $url, $m)) {
        $presentationId = $m[1];
        $params = [
            'start' => $slideStart ? 'true' : 'false',
            'loop' => $slideLoop ? 'true' : 'false',
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

function extractGoogleSlidesId(string $url): string {
    if (preg_match('~^https?://docs\.google\.com/presentation/d/([a-zA-Z0-9_-]+)~i', trim($url), $m)) {
        return (string)$m[1];
    }

    return '';
}

function extractSequenceKeyFromUrl(string $url): string {
    $url = trim($url);
    if ($url === '') {
        return '';
    }

    $parts = parse_url($url);
    if (!is_array($parts) || empty($parts['query'])) {
        return '';
    }

    parse_str((string)$parts['query'], $query);
    return trim((string)($query['preset'] ?? ''));
}

function urlsReferToSamePreset(string $left, string $right): bool {
    $left = trim($left);
    $right = trim($right);

    if ($left === '' || $right === '') {
        return false;
    }

    if ($left === $right) {
        return true;
    }

    $leftSlidesId = extractGoogleSlidesId($left);
    $rightSlidesId = extractGoogleSlidesId($right);
    if ($leftSlidesId !== '' && $leftSlidesId === $rightSlidesId) {
        return true;
    }

    $leftSequenceKey = extractSequenceKeyFromUrl($left);
    $rightSequenceKey = extractSequenceKeyFromUrl($right);

    return $leftSequenceKey !== '' && $leftSequenceKey === $rightSequenceKey;
}

function normalizeSourcePresetType(string $type, string $url = ''): string {
    $type = strtolower(trim($type));
    if ($type === 'presentation' || looksLikeGoogleSlides($url)) {
        return 'presentation';
    }

    return 'website';
}

function normalizePresetType(array $preset): string {
    return normalizeSourcePresetType(
        (string)($preset['type'] ?? ''),
        trim((string)($preset['url'] ?? ''))
    );
}

function normalizeSequenceItems(mixed $rawSequence): array {
    if (!is_array($rawSequence)) {
        return [];
    }

    $items = [];
    foreach ($rawSequence as $row) {
        if (!is_array($row)) {
            continue;
        }

        $presetUrl = trim((string)($row['preset_url'] ?? ''));
        $startTime = trim((string)($row['start_time'] ?? ''));
        $stopTime = trim((string)($row['stop_time'] ?? ''));

        if ($presetUrl === '' || !preg_match('/^\d{2}:\d{2}$/', $startTime) || !preg_match('/^\d{2}:\d{2}$/', $stopTime)) {
            continue;
        }

        $items[] = [
            'preset_url' => $presetUrl,
            'start_time' => $startTime,
            'stop_time' => $stopTime,
        ];
    }

    return $items;
}

function encodeSequenceConfigValue(array $sequenceItems): string {
    $normalized = normalizeSequenceItems($sequenceItems);
    if ($normalized === []) {
        return '';
    }

    $json = json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        return '';
    }

    return base64_encode($json);
}

function decodeSequenceConfigValue(string $encoded): array {
    $encoded = trim($encoded);
    if ($encoded === '') {
        return [];
    }

    $json = base64_decode($encoded, true);
    if (!is_string($json) || $json === '') {
        return [];
    }

    $decoded = json_decode($json, true);
    if (!is_array($decoded)) {
        return [];
    }

    return normalizeSequenceItems($decoded);
}

function buildPresetPayload(string $name, string $description, string $sourceUrl, string $sourceType): array {
    return [
        'name' => trim($name),
        'description' => trim($description),
        'type' => normalizeSourcePresetType($sourceType, trim($sourceUrl)),
        'url' => trim($sourceUrl),
    ];
}

function loadPresets(string $file): array {
    if (!is_readable($file)) {
        return [];
    }

    $json = file_get_contents($file);
    if ($json === false || trim($json) === '') {
        return [];
    }

    $data = json_decode($json, true);
    if (!is_array($data)) {
        return [];
    }

    $presets = [];
    foreach ($data as $row) {
        if (!is_array($row)) {
            continue;
        }

        $name = trim((string)($row['name'] ?? ''));
        $url = trim((string)($row['url'] ?? ''));
        $description = trim((string)($row['description'] ?? ''));
        $sourceUrl = trim((string)($row['source_url'] ?? ''));
        $sequenceKey = trim((string)($row['sequence_key'] ?? ''));
        $legacySequence = strtolower(trim((string)($row['type'] ?? ''))) === 'sequence';
        $effectiveUrl = $legacySequence && $sourceUrl !== '' ? $sourceUrl : $url;
        $effectiveType = $legacySequence
            ? normalizeSourcePresetType((string)($row['source_type'] ?? ''), $effectiveUrl)
            : normalizeSourcePresetType((string)($row['type'] ?? ''), $effectiveUrl);

        if ($name === '' || $effectiveUrl === '') {
            continue;
        }

        $presets[] = [
            'name' => $name,
            'url' => $effectiveUrl,
            'description' => $description,
            'type' => $effectiveType,
            'sequence_key' => $sequenceKey,
            'legacy_sequence_url' => $legacySequence ? $url : '',
            'sequence' => normalizeSequenceItems($row['sequence'] ?? []),
        ];
    }

    return $presets;
}

function savePresets(string $file, array $presets): bool {
    $normalized = [];

    foreach (array_values($presets) as $preset) {
        if (!is_array($preset)) {
            continue;
        }

        $row = [
            'name' => trim((string)($preset['name'] ?? '')),
            'url' => trim((string)($preset['url'] ?? '')),
            'description' => trim((string)($preset['description'] ?? '')),
        ];

        if ($row['name'] === '' || $row['url'] === '') {
            continue;
        }

        $row['type'] = normalizePresetType($preset);

        $sequenceItems = normalizeSequenceItems($preset['sequence'] ?? []);
        if ($sequenceItems !== []) {
            $row['sequence'] = $sequenceItems;

            $sequenceKey = trim((string)($preset['sequence_key'] ?? ''));
            if ($sequenceKey !== '') {
                $row['sequence_key'] = $sequenceKey;
            }
        }

        $normalized[] = $row;
    }

    $json = json_encode($normalized, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        return false;
    }

    return @file_put_contents($file, $json . PHP_EOL) !== false;
}

function findPresetIndexByUrl(array $presets, string $url): int {
    foreach ($presets as $index => $preset) {
        $presetUrl = trim((string)($preset['url'] ?? ''));
        $legacySequenceUrl = trim((string)($preset['legacy_sequence_url'] ?? ''));
        $presetSequenceKey = trim((string)($preset['sequence_key'] ?? ''));
        $candidateSequenceKey = extractSequenceKeyFromUrl($url);

        if (
            urlsReferToSamePreset($presetUrl, $url)
            || ($legacySequenceUrl !== '' && urlsReferToSamePreset($legacySequenceUrl, $url))
            || ($candidateSequenceKey !== '' && $presetSequenceKey !== '' && $candidateSequenceKey === $presetSequenceKey)
        ) {
            return (int)$index;
        }
    }

    return -1;
}

function findPresetByUrl(array $presets, string $url): ?array {
    $index = findPresetIndexByUrl($presets, $url);

    return $index >= 0 ? $presets[$index] : null;
}

function findPresetBySequenceKey(array $presets, string $sequenceKey): ?array {
    $sequenceKey = trim($sequenceKey);
    if ($sequenceKey === '') {
        return null;
    }

    foreach ($presets as $preset) {
        if (trim((string)($preset['sequence_key'] ?? '')) === $sequenceKey) {
            return $preset;
        }
    }

    return null;
}

function detectSystemTimezone(): string {
    $timezone = trim((string)@date_default_timezone_get());

    if ($timezone === '' || strtoupper($timezone) === 'UTC') {
        $localtimePath = '/etc/timezone';
        if (is_readable($localtimePath)) {
            $fileTimezone = trim((string)@file_get_contents($localtimePath));
            if ($fileTimezone !== '') {
                $timezone = $fileTimezone;
            }
        }
    }

    if ($timezone === '' || !in_array($timezone, timezone_identifiers_list(), true)) {
        $timezone = 'Europe/Brussels';
    }

    return $timezone;
}

function normalizeTimezoneName(?string $timezone): string {
    $timezone = trim((string)$timezone);

    if ($timezone === '' || !in_array($timezone, timezone_identifiers_list(), true)) {
        return detectSystemTimezone();
    }

    return $timezone;
}

function configBoolToString(bool $value): string {
    return $value ? 'true' : 'false';
}

function configStringToBool(string $value, bool $default = true): bool {
    $value = strtolower(trim($value));

    return match ($value) {
        'true', '1', 'yes', 'on' => true,
        'false', '0', 'no', 'off' => false,
        default => $default,
    };
}

function loadKioskConfig(string $file, array $defaults): array {
    $config = $defaults;
    $config['kiosk_mode'] = (string)($defaults['kiosk_mode'] ?? 'website');
    $config['selected_preset_url'] = (string)($defaults['selected_preset_url'] ?? '');
    $config['sequence_key'] = (string)($defaults['sequence_key'] ?? '');
    $config['resolved_preset_url'] = (string)($defaults['resolved_preset_url'] ?? '');
    $config['sequence_items'] = normalizeSequenceItems($defaults['sequence_items'] ?? []);
    $config['sequence_items_defined'] = (bool)($defaults['sequence_items_defined'] ?? false);
    $config['timezone'] = normalizeTimezoneName((string)($defaults['timezone'] ?? detectSystemTimezone()));

    if (!is_readable($file)) {
        return $config;
    }

    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) {
        return $config;
    }

    foreach ($lines as $line) {
        $line = trim((string)$line);
        if ($line === '' || str_starts_with($line, '#') || strpos($line, '=') === false) {
            continue;
        }

        [$key, $value] = array_map('trim', explode('=', $line, 2));

        switch ($key) {
            case 'KioskURL':
                $config['url'] = $value;

                if (looksLikeGoogleSlides($value)) {
                    $config['type'] = 'presentation';
                    $parts = parse_url($value);
                    if (!empty($parts['query'])) {
                        parse_str((string)$parts['query'], $query);

                        if (isset($query['start'])) {
                            $config['slide_start'] = configStringToBool((string)$query['start'], true);
                        }

                        if (isset($query['loop'])) {
                            $config['slide_loop'] = configStringToBool((string)$query['loop'], true);
                        }

                        if (isset($query['delayms']) && is_numeric((string)$query['delayms'])) {
                            $config['slide_seconds'] = max(1, (int)((int)$query['delayms'] / 1000));
                        }
                    }
                }
                break;

            case 'KioskMode':
                $mode = strtolower($value);
                if (in_array($mode, ['website', 'presentation', 'sequence'], true)) {
                    $config['kiosk_mode'] = $mode;
                }
                break;

            case 'SelectedPresetUrl':
                $config['selected_preset_url'] = $value;
                break;

            case 'SequenceKey':
                $config['sequence_key'] = $value;
                break;

            case 'ResolvedPresetUrl':
                $config['resolved_preset_url'] = $value;
                break;

            case 'SequenceData':
                $config['sequence_items'] = decodeSequenceConfigValue($value);
                $config['sequence_items_defined'] = true;
                break;

            case 'Timezone':
                $config['timezone'] = normalizeTimezoneName($value);
                break;

            case 'SlideStart':
                $config['slide_start'] = configStringToBool($value, true);
                break;

            case 'SlideLoop':
                $config['slide_loop'] = configStringToBool($value, true);
                break;

            case 'SlideDelay':
                if (is_numeric($value)) {
                    $config['slide_seconds'] = max(1, (int)((int)$value / 1000));
                }
                break;

            case 'RefreshTime':
                if (is_numeric($value)) {
                    $config['refresh_seconds'] = (int)$value;
                }
                break;

            case 'CacheInterval':
                if (is_numeric($value)) {
                    $config['cache_hours'] = (int)$value;
                }
                break;

            case 'StartTime':
                $config['on_time'] = $value;
                break;

            case 'StopTime':
                $config['off_time'] = $value;
                break;
        }
    }

    if ($config['resolved_preset_url'] !== '') {
        $config['type'] = normalizeSourcePresetType((string)$config['type'], (string)$config['resolved_preset_url']);
    } elseif ($config['url'] !== '') {
        $config['type'] = normalizeSourcePresetType((string)$config['type'], (string)$config['url']);
    }

    return $config;
}

function writeKioskConfig(string $file, array $cfg): bool {
    $timezone = normalizeTimezoneName((string)($cfg['timezone'] ?? detectSystemTimezone()));
    $kioskMode = strtolower(trim((string)($cfg['kiosk_mode'] ?? 'website')));
    if (!in_array($kioskMode, ['website', 'presentation', 'sequence'], true)) {
        $kioskMode = 'website';
    }

    $lines = [];
    $lines[] = '# ==========================================';
    $lines[] = '# Kiosk configuratiebestand';
    $lines[] = '# ==========================================';
    $lines[] = '';
    $lines[] = '# Runtime doel';
    $lines[] = 'KioskURL=' . trim((string)($cfg['url'] ?? ''));
    $lines[] = 'KioskMode=' . $kioskMode;
    $lines[] = 'SelectedPresetUrl=' . trim((string)($cfg['selected_preset_url'] ?? ''));
    $lines[] = 'SequenceKey=' . trim((string)($cfg['sequence_key'] ?? ''));
    $lines[] = 'ResolvedPresetUrl=' . trim((string)($cfg['resolved_preset_url'] ?? ''));
    $lines[] = 'SequenceData=' . encodeSequenceConfigValue((array)($cfg['sequence_items'] ?? []));
    $lines[] = 'Timezone=' . $timezone;
    $lines[] = '';
    $lines[] = '# Google Slides opties';
    $lines[] = 'SlideStart=' . configBoolToString((bool)($cfg['slide_start'] ?? true));
    $lines[] = 'SlideLoop=' . configBoolToString((bool)($cfg['slide_loop'] ?? true));
    $lines[] = 'SlideDelay=' . ((int)($cfg['slide_seconds'] ?? 10) * 1000);
    $lines[] = '';
    $lines[] = '# Refresh instellingen';
    $lines[] = 'RefreshTime=' . (int)($cfg['refresh_seconds'] ?? 30);
    $lines[] = 'CacheInterval=' . (int)($cfg['cache_hours'] ?? 2);
    $lines[] = '';
    $lines[] = '# Tijdschema (optioneel)';
    $lines[] = trim((string)($cfg['on_time'] ?? '')) !== '' ? 'StartTime=' . trim((string)$cfg['on_time']) : '#StartTime=08:00';
    $lines[] = trim((string)($cfg['off_time'] ?? '')) !== '' ? 'StopTime=' . trim((string)$cfg['off_time']) : '#StopTime=18:00';

    return @file_put_contents($file, implode(PHP_EOL, $lines) . PHP_EOL) !== false;
}

function timeToMinutes(string $value): int {
    [$hours, $minutes] = array_map('intval', explode(':', $value, 2));

    return ($hours * 60) + $minutes;
}

function normalizeNowForTimezone(DateTimeInterface|string|null $now, ?string $timezone = null): DateTimeImmutable {
    $timezoneName = normalizeTimezoneName($timezone);
    $tz = new DateTimeZone($timezoneName);

    if ($now instanceof DateTimeInterface) {
        return (new DateTimeImmutable($now->format(DateTimeInterface::ATOM)))->setTimezone($tz);
    }

    if (is_string($now) && trim($now) !== '') {
        return new DateTimeImmutable($now, $tz);
    }

    return new DateTimeImmutable('now', $tz);
}

function timeInRange(string $start, string $stop, DateTimeInterface|string|null $now = null, ?string $timezone = null): bool {
    $start = trim($start);
    $stop = trim($stop);

    if (!preg_match('/^\d{2}:\d{2}$/', $start) || !preg_match('/^\d{2}:\d{2}$/', $stop) || $start === $stop) {
        return false;
    }

    $current = normalizeNowForTimezone($now, $timezone);
    $currentMinutes = ((int)$current->format('H') * 60) + (int)$current->format('i');
    $startMinutes = timeToMinutes($start);
    $stopMinutes = timeToMinutes($stop);

    if ($startMinutes < $stopMinutes) {
        return $currentMinutes >= $startMinutes && $currentMinutes < $stopMinutes;
    }

    return $currentMinutes >= $startMinutes || $currentMinutes < $stopMinutes;
}

function resolveActiveSequenceSlot(array $sequenceItems, DateTimeInterface|string|null $now = null, ?string $timezone = null): ?array {
    foreach (normalizeSequenceItems($sequenceItems) as $slot) {
        if (timeInRange((string)$slot['start_time'], (string)$slot['stop_time'], $now, $timezone)) {
            return $slot;
        }
    }

    return null;
}

function resolveNextSequenceSlot(array $sequenceItems, DateTimeInterface|string|null $now = null, ?string $timezone = null): ?array {
    $slots = normalizeSequenceItems($sequenceItems);
    if ($slots === []) {
        return null;
    }

    $current = normalizeNowForTimezone($now, $timezone);
    $currentMinutes = ((int)$current->format('H') * 60) + (int)$current->format('i');
    $bestSlot = null;
    $bestDistance = PHP_INT_MAX;

    foreach ($slots as $slot) {
        $startMinutes = timeToMinutes((string)$slot['start_time']);
        $distance = $startMinutes >= $currentMinutes
            ? $startMinutes - $currentMinutes
            : (1440 - $currentMinutes) + $startMinutes;

        if ($distance < $bestDistance) {
            $bestDistance = $distance;
            $bestSlot = $slot;
        }
    }

    return $bestSlot;
}

function resolvePresetRuntimeUrl(array $preset, bool $slideStart, bool $slideLoop, int $slideSeconds): string {
    $presetUrl = trim((string)($preset['url'] ?? ''));
    $sourceType = normalizeSourcePresetType((string)($preset['type'] ?? ''), $presetUrl);

    return normalizeContentUrl($sourceType, $presetUrl, $slideStart, $slideLoop, $slideSeconds);
}

function labelForRuntimeUrl(string $url, string $fallbackName = ''): string {
    if (trim($fallbackName) !== '') {
        return trim($fallbackName);
    }

    if (looksLikeGoogleSlides($url)) {
        return 'Google Presentatie';
    }

    $host = parse_url($url, PHP_URL_HOST);
    if (is_string($host) && trim($host) !== '') {
        return preg_replace('/^www\./i', '', trim($host)) ?: $url;
    }

    return $url;
}

function resolveSequenceSlotTarget(array $slot, array $presets, array $config): ?array {
    $slotPresetUrl = trim((string)($slot['preset_url'] ?? ''));
    if ($slotPresetUrl === '') {
        return null;
    }

    $linkedPreset = findPresetByUrl($presets, $slotPresetUrl);
    if (is_array($linkedPreset)) {
        return [
            'resolved_preset_url' => trim((string)$linkedPreset['url']),
            'runtime_url' => resolvePresetRuntimeUrl(
                $linkedPreset,
                (bool)($config['slide_start'] ?? true),
                (bool)($config['slide_loop'] ?? true),
                (int)($config['slide_seconds'] ?? 10)
            ),
            'type' => normalizeSourcePresetType((string)($linkedPreset['type'] ?? ''), trim((string)$linkedPreset['url'])),
            'label' => trim((string)($linkedPreset['name'] ?? '')),
            'slot' => $slot,
        ];
    }

    if (!filter_var($slotPresetUrl, FILTER_VALIDATE_URL)) {
        return null;
    }

    $directType = normalizeSourcePresetType('', $slotPresetUrl);

    return [
        'resolved_preset_url' => $slotPresetUrl,
        'runtime_url' => normalizeContentUrl(
            $directType,
            $slotPresetUrl,
            (bool)($config['slide_start'] ?? true),
            (bool)($config['slide_loop'] ?? true),
            (int)($config['slide_seconds'] ?? 10)
        ),
        'type' => $directType,
        'label' => labelForRuntimeUrl($slotPresetUrl),
        'slot' => $slot,
    ];
}

function resolveSelectedPresetTarget(array $selectedPreset, array $config): ?array {
    $presetUrl = trim((string)($selectedPreset['url'] ?? ''));
    if ($presetUrl === '' || !filter_var($presetUrl, FILTER_VALIDATE_URL)) {
        return null;
    }

    $presetType = normalizeSourcePresetType((string)($selectedPreset['type'] ?? ''), $presetUrl);

    return [
        'resolved_preset_url' => $presetUrl,
        'runtime_url' => normalizeContentUrl(
            $presetType,
            $presetUrl,
            (bool)($config['slide_start'] ?? true),
            (bool)($config['slide_loop'] ?? true),
            (int)($config['slide_seconds'] ?? 10)
        ),
        'type' => $presetType,
        'label' => labelForRuntimeUrl($presetUrl, trim((string)($selectedPreset['name'] ?? 'Hoofdpreset'))),
        'slot' => null,
    ];
}

function resolveConfiguredSequenceItems(array $config, ?array $selectedPreset = null): array {
    $configuredItems = normalizeSequenceItems($config['sequence_items'] ?? []);
    if ($configuredItems !== [] || !empty($config['sequence_items_defined'])) {
        return $configuredItems;
    }

    if (is_array($selectedPreset)) {
        return normalizeSequenceItems($selectedPreset['sequence'] ?? []);
    }

    return [];
}

function resolveSequenceRuntimeData(array $selectedPreset, array $sequenceItems, array $presets, array $config, DateTimeInterface|string|null $now = null): array {
    $timezone = normalizeTimezoneName((string)($config['timezone'] ?? detectSystemTimezone()));
    $activeSlot = resolveActiveSequenceSlot($sequenceItems, $now, $timezone);
    $nextSlot = resolveNextSequenceSlot($sequenceItems, $now, $timezone);
    $resolvedTarget = is_array($activeSlot) ? resolveSequenceSlotTarget($activeSlot, $presets, $config) : null;
    $fallbackTarget = resolveSelectedPresetTarget($selectedPreset, $config);

    if (is_array($resolvedTarget)) {
        return [
            'status' => 'active',
            'active_slot' => $activeSlot,
            'next_slot' => $nextSlot,
            'resolved_preset_url' => trim((string)$resolvedTarget['resolved_preset_url']),
            'runtime_url' => trim((string)$resolvedTarget['runtime_url']),
            'label' => trim((string)$resolvedTarget['label']),
            'type' => trim((string)$resolvedTarget['type']),
            'fallback_target' => $fallbackTarget,
        ];
    }

    if (is_array($fallbackTarget)) {
        return [
            'status' => 'fallback',
            'active_slot' => $activeSlot,
            'next_slot' => $nextSlot,
            'resolved_preset_url' => trim((string)$fallbackTarget['resolved_preset_url']),
            'runtime_url' => trim((string)$fallbackTarget['runtime_url']),
            'label' => trim((string)$fallbackTarget['label']),
            'type' => trim((string)$fallbackTarget['type']),
            'fallback_target' => $fallbackTarget,
        ];
    }

    return [
        'status' => 'missing',
        'active_slot' => $activeSlot,
        'next_slot' => $nextSlot,
        'resolved_preset_url' => '',
        'runtime_url' => '',
        'label' => '',
        'type' => 'website',
        'fallback_target' => null,
    ];
}

function determineCurrentPresetState(array $presets, array $config): array {
    $selectedPresetUrl = trim((string)($config['selected_preset_url'] ?? ''));
    $selectedPreset = $selectedPresetUrl !== '' ? findPresetByUrl($presets, $selectedPresetUrl) : null;

    if (is_array($selectedPreset)) {
        return [
            'selected_url' => $selectedPresetUrl,
            'preset' => $selectedPreset,
        ];
    }

    $candidateUrls = [
        trim((string)($config['url'] ?? '')),
        trim((string)($config['resolved_preset_url'] ?? '')),
    ];

    foreach ($candidateUrls as $candidateUrl) {
        if ($candidateUrl === '') {
            continue;
        }

        $preset = findPresetByUrl($presets, $candidateUrl);
        if (is_array($preset)) {
            return [
                'selected_url' => trim((string)$preset['url']),
                'preset' => $preset,
            ];
        }
    }

    $sequenceKey = trim((string)($config['sequence_key'] ?? ''));
    if ($sequenceKey !== '') {
        $preset = findPresetBySequenceKey($presets, $sequenceKey);
        if (is_array($preset)) {
            return [
                'selected_url' => trim((string)$preset['url']),
                'preset' => $preset,
            ];
        }
    }

    return [
        'selected_url' => '',
        'preset' => null,
    ];
}

function validateSequenceItemsAgainstPresets(array $sequenceItems, array $presets, string $selectedPresetUrl = ''): ?string {
    foreach ($sequenceItems as $index => $item) {
        $start = trim((string)($item['start_time'] ?? ''));
        $stop = trim((string)($item['stop_time'] ?? ''));
        $presetUrl = trim((string)($item['preset_url'] ?? ''));

        if ($start === '' || $stop === '' || $presetUrl === '') {
            return 'Elk sequence tijdslot moet een preset, starttijd en stoptijd hebben.';
        }

        if ($start === $stop) {
            return 'Een sequence tijdslot mag niet dezelfde start- en stoptijd hebben.';
        }

        if ($selectedPresetUrl !== '' && urlsReferToSamePreset($selectedPresetUrl, $presetUrl)) {
            return 'Een sequence tijdslot moet een andere preset gebruiken dan de hoofdpreset.';
        }

        $linkedPreset = findPresetByUrl($presets, $presetUrl);
        if (!is_array($linkedPreset) && !filter_var($presetUrl, FILTER_VALIDATE_URL)) {
            return 'Sequence tijdsloten mogen alleen naar geldige presets verwijzen.';
        }
    }

    return null;
}

function formatSequenceSlotSummary(?array $slot, array $presets): string {
    if (!is_array($slot)) {
        return '';
    }

    $label = trim((string)($slot['preset_url'] ?? ''));
    $linkedPreset = findPresetByUrl($presets, $label);
    if (is_array($linkedPreset)) {
        $label = trim((string)($linkedPreset['name'] ?? $label));
    } else {
        $label = labelForRuntimeUrl($label);
    }

    return $slot['start_time'] . '-' . $slot['stop_time'] . ' / ' . $label;
}
