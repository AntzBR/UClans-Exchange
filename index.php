<?php

declare(strict_types=1);

// Self-contained /exchange/ application. No external site bootstrap.
ini_set('display_errors', '0');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; script-src 'self'; style-src 'self'; img-src 'self' data:; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");

// A narrowly scoped anonymous session is only used for CSRF protection.
$scriptDir = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/exchange/index.php')));
$cookiePath = $scriptDir === '/' || $scriptDir === '.' ? '/' : rtrim($scriptDir, '/') . '/';
$https = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
    || (string) ($_SERVER['SERVER_PORT'] ?? '') === '443';
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('uc_exchange_session');
    session_set_cookie_params([
        'lifetime' => 0, 'path' => $cookiePath, 'secure' => $https,
        'httponly' => true, 'samesite' => 'Lax',
    ]);
    session_start();
}
if (!isset($_SESSION['exchange_csrf'])) {
    $_SESSION['exchange_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = (string) $_SESSION['exchange_csrf'];
$csrfSubmitted = is_string($_POST['exchange_csrf'] ?? null) ? $_POST['exchange_csrf'] : '';
$csrfValid = hash_equals($csrfToken, $csrfSubmitted);
session_write_close();

$pageTitle = 'UltimateClans V8 → V9 Exchange';

function e(string $v): string
{
    return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function countReport(?array $result, string $key): int
{
    return isset($result['report'][$key]) && is_array($result['report'][$key])
        ? count($result['report'][$key])
        : 0;
}

// Standalone English strings. No dependency on a site's translation function.
function xt(string $key, string $fallback): string
{
    return $fallback;
}

/*
|--------------------------------------------------------------------------
| Conversor
|--------------------------------------------------------------------------
*/
$cfg = require __DIR__ . '/_private/config.php';
require_once __DIR__ . '/_private/lib/Converter.php';

require_once __DIR__ . '/_private/lib/RateLimiter.php';
$converter = new UltimateClansConverter($cfg);
$converter->cleanup();

$zipAvailable = class_exists('ZipArchive');
$sqliteAvailable = class_exists('PDO') && in_array('sqlite', PDO::getAvailableDrivers(), true);

/* Random bearer tokens expire after result_ttl; no account/login needed. */
if (isset($_GET['report'])) {
    $token = (string) $_GET['report'];
    $file = $converter->resultReport($token);

    if (!$file) {
        http_response_code(404);
        exit(xt('exchange_report_not_found', 'Report not found or expired.'));
    }

    header('Content-Type: text/plain; charset=UTF-8');
    header('Content-Disposition: attachment; filename="UltimateClans-V8-to-V9-Migration-Report.txt"');
    header('Content-Length: ' . filesize($file));
    header('X-Content-Type-Options: nosniff');
    readfile($file);
    exit;
}

if (isset($_GET['download'])) {
    $token = (string) $_GET['download'];
    $file = $converter->resultZip($token);

    if (!$file) {
        http_response_code(404);
        exit(xt('exchange_result_not_found', 'Result not found or expired.'));
    }

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="UltimateClans-V9-Converted.zip"');
    header('Content-Length: ' . filesize($file));
    header('X-Content-Type-Options: nosniff');
    readfile($file);
    exit;
}

$result = null;
$error = null;
$migrateStorage = $_SERVER['REQUEST_METHOD'] !== 'POST' || isset($_POST['migrate_storage']);
$migrateMaterials = $_SERVER['REQUEST_METHOD'] !== 'POST' || isset($_POST['migrate_materials']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (empty($_POST) && empty($_FILES) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
            throw new RuntimeException('The upload exceeds the web server PHP post_max_size limit.');
        }
        if (!$csrfValid) {
            throw new RuntimeException('Invalid or expired form token. Reload the page and try again.');
        }
        if (!$zipAvailable) {
            throw new RuntimeException('The PHP ZipArchive extension is not installed.');
        }
        if (!isset($_FILES['v8_zip']) || !is_array($_FILES['v8_zip'])) {
            throw new RuntimeException(xt('exchange_error_select_zip', 'Select a V8 ZIP file.'));
        }

        $upload = $_FILES['v8_zip'];

        if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException(
                xt('exchange_error_upload', 'Upload failed. PHP upload error:') . ' ' . ($upload['error'] ?? 'unknown')
            );
        }

        if (($upload['size'] ?? 0) > $cfg['max_upload_bytes']) {
            throw new RuntimeException(xt('exchange_error_too_large', 'The ZIP is larger than the allowed limit.'));
        }

        $name = strtolower((string) ($upload['name'] ?? ''));

        if (!str_ends_with($name, '.zip')) {
            throw new RuntimeException(xt('exchange_error_zip_only', 'Only .zip files are accepted.'));
        }

        if (!is_string($upload['tmp_name'] ?? null) || !is_uploaded_file($upload['tmp_name'])) {
            throw new RuntimeException('The uploaded file was not received correctly.');
        }

        $lockFile = $cfg['runtime'] . '/conversion.lock';
        $lock = fopen($lockFile, 'c+');
        if (!$lock) {
            throw new RuntimeException('Conversion service storage is unavailable.');
        }
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                throw new RuntimeException('Another conversion is running. Please try again shortly.');
            }
            ExchangeRateLimiter::record($cfg, (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
            $result = $converter->convertZip((string) $upload['tmp_name'], $migrateStorage, $migrateMaterials);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?></title>
    <meta name="description" content="Migrate UltimateClans V8 configuration and supported data to V9.">
    <link rel="stylesheet" href="assets/style.css?v=2">
    <script src="assets/app.js" defer></script>
</head>
<body>
<header class="site-head">
    <div class="site-head-inner">
        <a class="site-brand" href="./" aria-label="UltimateClans Exchange home"><span class="site-symbol">↗</span><span>ULTIMATE<span class="brand-soft">CLANS</span> <span class="brand-badge">EXCHANGE</span></span></a>
        <span class="site-version">V8 <span aria-hidden="true">→</span> V9</span>
    </div>
</header>



<main class="exchange-wrapper">
    <div class="products-header">
        <h2><span><?= e(xt('exchange_title', 'UltimateClans V8 → V9 Exchange')) ?></span></h2>
        <p><?= e(xt('exchange_description', 'Upload your V8 plugin folder as a ZIP. The converter keeps V9 defaults, migrates customized settings, optionally migrates storage data and normalizes legacy V8 material names for V9.')) ?></p>
    </div>

    <?php if ($error): ?>
        <section class="exchange-card exchange-error">
            <div class="exchange-error-title">
                <i class="fa-solid fa-triangle-exclamation"></i>
                <strong><?= e(xt('exchange_conversion_failed', 'Conversion failed')) ?></strong>
            </div>
            <div><?= e($error) ?></div>
        </section>
    <?php endif; ?>

    <section class="exchange-card">
        <form method="post" enctype="multipart/form-data" id="exchangeForm">
            <input type="hidden" name="exchange_csrf" value="<?= e($csrfToken) ?>">

            <div class="exchange-section-heading">
                <div>
                    <span class="exchange-step"><?= e(xt('exchange_step_source', '01 / SOURCE FILE')) ?></span>
                    <h3><?= e(xt('exchange_upload_heading', 'Upload your V8 files')) ?></h3>
                </div>
                <span class="exchange-format">.ZIP</span>
            </div>

            <div class="exchange-upload-area">
                <label class="exchange-drop" for="v8_zip">
                    <span class="exchange-drop-icon"><i class="fa-solid fa-file-zipper" aria-hidden="true"></i></span>
                    <span class="exchange-drop-copy">
                        <strong id="fileLabel" data-default-label="<?= e(xt('exchange_select_zip', 'Select the UltimateClans V8 ZIP')) ?>"><?= e(xt('exchange_select_zip', 'Select the UltimateClans V8 ZIP')) ?></strong>
                        <span class="exchange-drop-hint"><?= e(xt('exchange_file_click_help', 'Choose the ZIP exported from your old server')) ?></span>
                    </span>
                    <span class="exchange-file-choose" aria-hidden="true"><?= e(xt('exchange_browse_zip', 'Browse files')) ?></span>
                </label>
                <input id="v8_zip" class="exchange-file-input" type="file" name="v8_zip" accept=".zip,application/zip" required>
            </div>
            <p class="exchange-file-help"><?= e(xt('exchange_zip_structure', 'The ZIP can contain the full plugin folder or the UltimateClans files directly at its root.')) ?></p>
            <details class="exchange-upload-details">
                <summary><?= e(xt('exchange_upload_details_title', 'What does the converter preserve?')) ?></summary>
                <p><?= e(xt('exchange_zip_help', 'The ZIP may contain the entire plugin folder or the UltimateClans files directly at the ZIP root. Storage migration is optional. Legacy material conversion is recommended to replace old Bukkit material names that no longer exist in V9-supported Minecraft versions. Custom language files are preserved when present, but their YAML schema is not automatically translated to new V9 keys. Only detected V8 addons are included.')) ?></p>
            </details>

            <div class="exchange-options-header">
                <span class="exchange-step"><?= e(xt('exchange_step_options', '02 / MIGRATION OPTIONS')) ?></span>
                <h3><?= e(xt('exchange_options_heading', 'Conversion settings')) ?></h3>
                <p><?= e(xt('exchange_options_help', 'Both options are enabled by default. You can adjust them before converting.')) ?></p>
            </div>

            <div class="exchange-options">
                <label class="exchange-storage-option" for="migrate_storage">
                    <input id="migrate_storage" type="checkbox" name="migrate_storage" value="1" <?= $migrateStorage ? 'checked' : '' ?>>
                    <span class="exchange-toggle" aria-hidden="true"></span>
                    <span class="exchange-storage-text">
                        <strong><?= e(xt('exchange_migrate_storage', 'Migrate storage data')) ?></strong>
                        <span><?= e(xt('exchange_migrate_storage_help', 'When enabled, active SQLite and compatible YAML data are migrated. Disable this to convert configuration files only.')) ?></span>
                    </span>
                </label>
                <label class="exchange-storage-option" for="migrate_materials">
                    <input id="migrate_materials" type="checkbox" name="migrate_materials" value="1" <?= $migrateMaterials ? 'checked' : '' ?>>
                    <span class="exchange-toggle" aria-hidden="true"></span>
                    <span class="exchange-storage-text">
                        <strong><?= e(xt('exchange_migrate_materials', 'Convert legacy V8 materials')) ?></strong>
                        <span><?= e(xt('exchange_migrate_materials_help', 'Recommended. Converts known legacy Bukkit material names and old material + data variants to modern V9-compatible names.')) ?></span>
                    </span>
                </label>
            </div>

            <div class="exchange-form-action">
                <button class="btn-stripe-checkout exchange-submit" type="submit" data-progress-label="<?= e(xt('exchange_converting', 'Converting…')) ?>" <?= !$zipAvailable ? 'disabled' : '' ?>>
                    <i class="fa-solid fa-arrows-rotate" aria-hidden="true"></i>
                    <span><?= e(xt('exchange_convert_button', 'Convert to V9')) ?></span>
                </button>
                <div class="exchange-notice">
                    <p>
                        <?= e(xt('exchange_max_upload', 'Maximum upload:')) ?>
                        <strong><?= number_format($cfg['max_upload_bytes'] / 1024 / 1024, 0) ?> MB</strong>
                        <span aria-hidden="true">·</span>
                        <?= e(xt('exchange_expire_notice', 'Converted files expire automatically after')) ?>
                        <strong><?= number_format($cfg['result_ttl'] / 60) ?> <?= e(xt('exchange_minutes', 'minutes')) ?></strong>
                    </p>
                    <div class="exchange-server-status">
                        <span class="exchange-status-title"><?= e(xt('exchange_server_support', 'Server support:')) ?></span>
                        <span class="exchange-server-item">ZipArchive <strong class="<?= $zipAvailable ? 'ok' : 'warn' ?>"><?= e($zipAvailable ? xt('exchange_available', 'available') : xt('exchange_missing', 'missing')) ?></strong></span>
                        <span class="exchange-server-item">PDO_SQLite <strong class="<?= $sqliteAvailable ? 'ok' : 'warn' ?>"><?= e($sqliteAvailable ? xt('exchange_available', 'available') : xt('exchange_sqlite_missing', 'missing — SQLite data migration disabled')) ?></strong></span>
                        <span class="exchange-server-item">YAML <strong class="ok"><?= e(xt('exchange_builtin', 'built-in')) ?></strong></span>
                    </div>
                </div>
            </div>
        </form>
    </section>

    <?php if ($result): ?>
        <?php
        $finalStatus = (string)($result['final_status'] ?? 'REVIEW_REQUIRED');
        $finalStatusClass = match ($finalStatus) {
            'SAFE' => 'safe',
            'SAFE_WITH_CHANGES' => 'change',
            'UNSAFE_DATA' => 'unsafe',
            default => 'review',
        };
        $finalStatusIcon = match ($finalStatus) {
            'SAFE' => 'fa-circle-check',
            'SAFE_WITH_CHANGES' => 'fa-triangle-exclamation',
            'UNSAFE_DATA' => 'fa-circle-xmark',
            default => 'fa-magnifying-glass',
        };
        $finalStatusDesc = match ($finalStatus) {
            'SAFE' => xt('exchange_final_safe_desc', 'Final validator passed and no unresolved migration choices remain. The generated package is ready for deployment.'),
            'SAFE_WITH_CHANGES' => xt('exchange_final_changes_desc', 'The package passed structural/data validation, but known V8 defaults or version-sensitive values were intentionally changed or held.'),
            'UNSAFE_DATA' => xt('exchange_final_unsafe_desc', 'A relational, storage, package or final-validator error was detected. Do not deploy this result until the reported data issue is corrected.'),
            default => xt('exchange_final_review_desc', 'The package is structurally valid, but one or more migration choices still require review before deployment.'),
        };
        ?>
        <section class="exchange-card">
            <h2 class="exchange-result-title <?= e($finalStatusClass) ?>"><i class="fa-solid <?= e($finalStatusIcon) ?>" ></i> <?= e(xt('exchange_complete', 'Conversion complete')) ?></h2>
            <div class="exchange-small">
                <?= e(xt('exchange_detected', 'Detected')) ?> V8 <?= e((string) $result['source_version']) ?> → V9 <?= e((string) $result['target_version']) ?>
            </div>
            <div class="exchange-mode">
                <?= e(xt('exchange_conversion_mode', 'Conversion mode:')) ?>
                <strong><?= !empty($result['storage_migration']) ? e(xt('exchange_mode_full', 'Configuration + storage data')) : e(xt('exchange_mode_config_only', 'Configuration only')) ?></strong>
            </div>
            <div class="exchange-mode">
                <?= e(xt('exchange_material_mode', 'Legacy materials:')) ?>
                <strong><?= !array_key_exists('material_migration', $result) || !empty($result['material_migration']) ? e(xt('exchange_material_enabled', 'automatic conversion enabled')) : e(xt('exchange_material_disabled', 'conversion disabled')) ?></strong>
            </div>
            <div class="exchange-mode">
                <?= e(xt('exchange_reference_mode', 'V8 comparison reference:')) ?>
                <strong><?= e((string)($result['reference_version'] ?? 'unknown')) ?></strong>
                · <?= e(xt('exchange_compatibility_mode', 'compatibility mode:')) ?>
                <strong><?= e((string)($result['compatibility_mode'] ?? 'NORMAL')) ?></strong>
            </div>

            <div class="exchange-final-status <?= e($finalStatusClass) ?>">
                <i class="fa-solid <?= e($finalStatusIcon) ?>"></i>
                <div>
                    <strong><?= e($finalStatus) ?></strong>
                    <span><?= e($finalStatusDesc) ?></span>
                </div>
            </div>

            <div class="exchange-stats">
                <div class="exchange-stat"><strong class="ok"><?= countReport($result, 'migrated') ?></strong><?= e(xt('exchange_stat_same', 'same-location changes')) ?></div>
                <div class="exchange-stat"><strong class="ok"><?= countReport($result, 'moved') ?></strong><?= e(xt('exchange_stat_moved', 'moved to V9')) ?></div>
                <div class="exchange-stat"><strong class="ok"><?= countReport($result, 'compatibility') ?></strong><?= e(xt('exchange_stat_compat', 'legacy/compat')) ?></div>
                <div class="exchange-stat"><strong class="warn"><?= countReport($result, 'ignored') ?></strong><?= e(xt('exchange_stat_ignored', 'known removed/obsolete')) ?></div>
                <div class="exchange-stat"><strong class="ok"><?= countReport($result, 'smart_preserved') ?></strong><?= e(xt('exchange_stat_smart_preserved', 'smart-preserved values')) ?></div>
                <div class="exchange-stat"><strong class="warn"><?= countReport($result, 'conservative') ?></strong><?= e(xt('exchange_stat_held', 'safe-mode held values')) ?></div>
                <div class="exchange-stat"><strong class="warn"><?= countReport($result, 'unmapped') ?></strong><?= e(xt('exchange_stat_review', 'manual review items')) ?></div>
                <div class="exchange-stat"><strong class="warn"><?= countReport($result, 'addons_skipped') ?></strong><?= e(xt('exchange_stat_addons_skipped', 'addons not migrated')) ?></div>
                <div class="exchange-stat"><strong class="ok"><?= countReport($result, 'storage') ?></strong><?= e(xt('exchange_stat_storage', 'SQLite checks/migrations')) ?></div>
                <div class="exchange-stat"><strong class="<?= countReport($result, 'data_validation_errors') > 0 ? 'warn' : 'ok' ?>"><?= countReport($result, 'data_validation') ?></strong><?= e(xt('exchange_stat_relational', 'relational checks')) ?></div>
                <div class="exchange-stat"><strong class="warn"><?= countReport($result, 'data_validation_errors') ?></strong><?= e(xt('exchange_stat_relational_errors', 'relational errors')) ?></div>
                <div class="exchange-stat"><strong class="ok"><?= countReport($result, 'yaml_storage') ?></strong><?= e(xt('exchange_stat_yaml_storage', 'YAML checks/migrations')) ?></div>
                <div class="exchange-stat"><strong class="ok"><?= countReport($result, 'materials') ?></strong><?= e(xt('exchange_stat_materials', 'material conversions')) ?></div>
                <div class="exchange-stat"><strong class="warn"><?= countReport($result, 'material_fallbacks') + countReport($result, 'material_warnings') ?></strong><?= e(xt('exchange_stat_material_review', 'material reviews')) ?></div>
                <div class="exchange-stat"><strong class="<?= countReport($result, 'final_validation_errors') > 0 ? 'warn' : 'ok' ?>"><?= countReport($result, 'final_validation') ?></strong><?= e(xt('exchange_stat_final_validator', 'final validator checks')) ?></div>
                <div class="exchange-stat"><strong class="warn"><?= countReport($result, 'final_validation_errors') ?></strong><?= e(xt('exchange_stat_final_errors', 'final validator errors')) ?></div>
            </div>

            <?php if (countReport($result, 'unmapped') + countReport($result, 'addons_skipped') + countReport($result, 'material_fallbacks') + countReport($result, 'material_warnings') > 0): ?>
                <div class="exchange-review-warning">
                    <strong><?= e(xt('exchange_manual_review_title', 'Manual review required:')) ?></strong>
                    <?= e(xt('exchange_manual_review_desc', 'one or more values/addons/materials still require a migration decision. Review the corresponding sections before deployment.')) ?>
                </div>
            <?php endif; ?>

            <?php if (countReport($result, 'storage_errors') + countReport($result, 'yaml_storage_errors') + countReport($result, 'data_validation_errors') + countReport($result, 'package_errors') + countReport($result, 'final_validation_errors') > 0): ?>
                <div class="exchange-storage-error">
                    <strong><?= e(xt('exchange_data_issue_title', 'Data migration issue:')) ?></strong>
                    <?= e(xt('exchange_data_issue_desc', 'configuration conversion completed, but one or more storage, relational or package-safety checks failed. Do not use this result in production until those errors are reviewed.')) ?>
                </div>
            <?php endif; ?>


            <?php if (($result['compatibility_mode'] ?? 'NORMAL') === 'SAFE_CONSERVATIVE'): ?>
                <div class="exchange-review-warning">
                    <strong><?= e(xt('exchange_safe_mode_title', 'Safe compatibility mode:')) ?></strong>
                    <?= e(xt('exchange_safe_mode_desc', 'the uploaded V8 release is far from the available comparison reference. V15.1 preserves verified user-facing same-path values automatically and only holds values that remain version-sensitive.')) ?>
                </div>
            <?php endif; ?>

            <?php if (countReport($result, 'material_fallbacks') + countReport($result, 'material_warnings') > 0): ?>
                <div class="exchange-review-warning">
                    <strong><?= e(xt('exchange_material_review_title', 'Material review recommended:')) ?></strong>
                    <?= e(xt('exchange_material_review_desc', 'one or more legacy materials required a V9 fallback or could not be converted safely. Review the material sections below.')) ?>
                </div>
            <?php endif; ?>

            <div class="exchange-actions">
                <a class="btn btn-blue" href="?download=<?= e((string) $result['token']) ?>">
                    <i class="fa-solid fa-download"></i> <?= e(xt('exchange_download_zip', 'Download converted V9 ZIP')) ?>
                </a>
                <a class="btn btn-secondary" href="?report=<?= e((string) $result['token']) ?>">
                    <i class="fa-solid fa-file-lines"></i> <?= e(xt('exchange_download_report', 'Download migration report')) ?>
                </a>
            </div>

            <?php
            $reportSections = [
                'data_validation_errors' => xt('exchange_section_relational_errors', 'Relational data errors'),
                'package_errors' => xt('exchange_section_package_errors', 'Package safety errors'),
                'final_validation_errors' => xt('exchange_section_final_errors', 'Final validator errors'),
                'addons_skipped' => xt('exchange_section_addons_skipped', 'Addons not migrated'),
                'smart_preserved' => xt('exchange_section_smart_preserved', 'Smart-preserved settings'),
                'conservative' => xt('exchange_section_conservative', 'Safe-mode held settings'),
                'language_safety' => xt('exchange_section_language_safety', 'Language safety'),
                'storage' => xt('exchange_section_storage', 'SQLite data migration'),
                'storage_warnings' => xt('exchange_section_storage_warnings', 'SQLite data warnings'),
                'storage_errors' => xt('exchange_section_storage_errors', 'SQLite data errors'),
                'data_validation' => xt('exchange_section_relational', 'Relational data validation'),
                'data_validation_warnings' => xt('exchange_section_relational_warnings', 'Relational data warnings'),
                'yaml_storage' => xt('exchange_section_yaml_storage', 'YAML data migration'),
                'yaml_storage_warnings' => xt('exchange_section_yaml_storage_warnings', 'YAML data warnings'),
                'yaml_storage_errors' => xt('exchange_section_yaml_storage_errors', 'YAML data errors'),
                'materials' => xt('exchange_section_materials', 'Material conversions'),
                'material_fallbacks' => xt('exchange_section_material_fallbacks', 'Material fallbacks'),
                'material_warnings' => xt('exchange_section_material_warnings', 'Material warnings'),
                'package_safety' => xt('exchange_section_package_safety', 'Package safety'),
                'final_validation' => xt('exchange_section_final_validator', 'Final validator'),
                'final_validation_warnings' => xt('exchange_section_final_warnings', 'Final validator warnings'),
                'moved' => xt('exchange_section_moved', 'Moved settings'),
                'migrated' => xt('exchange_section_migrated', 'Migrated in place'),
                'compatibility' => xt('exchange_section_compatibility', 'Compatibility / legacy migrations'),
                'ignored' => xt('exchange_section_ignored', 'Known removed / obsolete V8 settings'),
                'unmapped' => xt('exchange_section_unmapped', 'Needs manual review'),
                'warnings' => xt('exchange_section_notes', 'Notes'),
            ];

            foreach ($reportSections as $key => $title):
                $items = $result['report'][$key] ?? [];
                if (!$items) {
                    continue;
                }
                ?>
                <details class="exchange-details">
                    <summary><?= e($title) ?> (<?= count($items) ?>)</summary>
                    <ul class="exchange-list">
                        <?php foreach ($items as $item): ?>
                            <li><code><?= e((string) $item) ?></code></li>
                        <?php endforeach; ?>
                    </ul>
                </details>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>
</main>

<footer class="site-footer"><span>UltimateClans Exchange · Independent migration tool</span><span>Review the migration report before replacing server files.</span></footer>
</body>
</html>
