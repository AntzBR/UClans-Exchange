<?php

declare(strict_types=1);

require_once __DIR__ . '/YamlDocument.php';
require_once __DIR__ . '/MigrationRules.php';
require_once __DIR__ . '/SqliteMigrator.php';
require_once __DIR__ . '/YamlStorageMigrator.php';
require_once __DIR__ . '/MaterialMigrator.php';
require_once __DIR__ . '/SmartPreservationPolicy.php';
require_once __DIR__ . '/FinalValidator.php';
require_once __DIR__ . '/CompatibilityPolicy.php';

final class UltimateClansConverter
{
    private array $cfg;
    private array $report = [];
    private array $docCache = [];
    private array $ops = [];
    private array $legacyRoles = [];
    private bool $conservativeMode = false;
    private ?string $sourceVersion = null;
    private ?string $referenceVersion = null;
    private bool $legacyStructureDetected = false;

    public function __construct(array $cfg)
    {
        $this->cfg = $cfg;
    }

    public function convertZip(string $uploadedZip, bool $migrateStorage = true, bool $migrateMaterials = true): array
    {
        $this->report = $this->emptyReport();
        $this->docCache = [];
        $this->ops = [];
        $this->legacyRoles = [];
        $this->conservativeMode = false;
        $this->sourceVersion = null;
        $this->referenceVersion = null;
        $this->legacyStructureDetected = false;
        $this->ensureRuntime();
        $token = bin2hex(random_bytes(16));
        $work = $this->cfg['runtime'] . '/work/' . $token;
        $input = $work . '/input';
        $output = $work . '/output/UltimateClans-V9';
        @mkdir($input, 0700, true);
        @mkdir($output, 0700, true);

        try {
        @set_time_limit(300);
        $this->extractSupportedFiles($uploadedZip, $input);
        $root = $this->detectV8Root($input);
        $this->sourceVersion = $this->readVersion($root . '/config.yml');
        $this->referenceVersion = $this->readVersion($this->cfg['templates_v8'] . '/config.yml');
        $this->conservativeMode = $this->legacyStructureDetected
            ? true
            : $this->shouldUseConservativeMode($this->sourceVersion, $this->referenceVersion);
        if ($this->legacyStructureDetected && $this->sourceVersion === null) {
            $this->report['warnings'][] = 'UltimateClans V8 was detected by its legacy file/configuration structure because no readable V8 version field was found. SAFE_CONSERVATIVE mode was forced.';
        }

        $this->copyTree($this->cfg['templates_v9'], $output);
        $this->syncAddonSelection($root, $output);
        $this->migrate($root, $output);

        $languagePolicy = $this->preserveLanguageSelectionAndFiles($root, $output);
        if ($migrateStorage) {
            SqliteMigrator::migrate($root, $output, $this->cfg['sqlite_templates_v9'], $this->report, $languagePolicy);
            YamlStorageMigrator::migrate($root, $output, $this->cfg['yaml_templates_v9'], $this->report);
            $this->report['warnings'][] = 'Active SQLite and YAML storage are migrated when detected; inactive/stale storage files are not used.';
        } else {
            $this->report['warnings'][] = 'Storage data migration was disabled by the user. SQLite and YAML data were not migrated; only configuration files were converted.';
        }
        if ($migrateMaterials) {
            MaterialMigrator::migrate($output, $this->cfg['templates_v9'], $this->report);
        } else {
            $this->report['warnings'][] = 'Legacy material conversion was disabled by the user. Migrated material names were left unchanged.';
        }

        $this->sanitizeOutput($output);
        FinalValidator::validate($output, $this->report, $this->supportedV9Languages(), $this->cfg['templates_v9']);
        $this->deduplicateReport();

        $finalStatus = $this->calculateFinalStatus($this->report);

        $zipPath = $this->cfg['runtime'] . '/results/' . $token . '.zip';
        $this->zipDirectory($output, $zipPath, 'UltimateClans-V9');

        $meta = [
            'token' => $token,
            'created_at' => time(),
            'source_version' => $this->sourceVersion,
            'reference_version' => $this->referenceVersion,
            'target_version' => $this->readVersion($this->cfg['templates_v9'] . '/config.yml'),
            'compatibility_mode' => $this->conservativeMode ? 'SAFE_CONSERVATIVE' : 'NORMAL',
            'final_status' => $finalStatus,
            'storage_migration' => $migrateStorage,
            'material_migration' => $migrateMaterials,
            'report' => $this->report,
        ];
        file_put_contents($this->cfg['runtime'] . '/results/' . $token . '.json', json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        file_put_contents($this->cfg['runtime'] . '/results/' . $token . '.txt', $this->buildReportText($meta));
        return $meta;
        } finally {
            // Also removes uploaded player data after a failed conversion.
            $this->removeTree($work);
        }
    }

    private function emptyReport(): array
    {
        return [
            'migrated' => [], 'moved' => [], 'compatibility' => [], 'ignored' => [], 'unmapped' => [],
            'conservative' => [], 'smart_preserved' => [], 'addons_skipped' => [], 'language_safety' => [],
            'warnings' => [], 'storage' => [], 'storage_warnings' => [], 'storage_errors' => [],
            'data_validation' => [], 'data_validation_warnings' => [], 'data_validation_errors' => [],
            'yaml_storage' => [], 'yaml_storage_warnings' => [], 'yaml_storage_errors' => [],
            'materials' => [], 'material_fallbacks' => [], 'material_warnings' => [],
            'package_safety' => [], 'package_errors' => [],
            'final_validation' => [], 'final_validation_warnings' => [], 'final_validation_errors' => [],
        ];
    }

    public function result(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) return null;
        $json = $this->cfg['runtime'] . '/results/' . $token . '.json';
        if (!is_file($json)) return null;
        $data = json_decode((string)file_get_contents($json), true);
        return is_array($data) ? $data : null;
    }

    public function resultZip(string $token): ?string
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) return null;
        $file = $this->cfg['runtime'] . '/results/' . $token . '.zip';
        return is_file($file) ? $file : null;
    }

    public function resultReport(string $token): ?string
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) return null;
        $file = $this->cfg['runtime'] . '/results/' . $token . '.txt';
        return is_file($file) ? $file : null;
    }

    private function buildReportText(array $meta): string
    {
        $report = is_array($meta['report'] ?? null) ? $meta['report'] : [];
        $get = static fn(string $key): array => is_array($report[$key] ?? null) ? $report[$key] : [];

        $migrated = $get('migrated');
        $moved = $get('moved');
        $compatibility = $get('compatibility');
        $ignored = $get('ignored');
        $unmapped = $get('unmapped');
        $conservative = $get('conservative');
        $smartPreserved = $get('smart_preserved');
        $addonsSkipped = $get('addons_skipped');
        $languageSafety = $get('language_safety');
        $warnings = $get('warnings');
        $storage = $get('storage');
        $storageWarnings = $get('storage_warnings');
        $storageErrors = $get('storage_errors');
        $dataValidation = $get('data_validation');
        $dataValidationWarnings = $get('data_validation_warnings');
        $dataValidationErrors = $get('data_validation_errors');
        $yamlStorage = $get('yaml_storage');
        $yamlStorageWarnings = $get('yaml_storage_warnings');
        $yamlStorageErrors = $get('yaml_storage_errors');
        $materials = $get('materials');
        $materialFallbacks = $get('material_fallbacks');
        $materialWarnings = $get('material_warnings');
        $packageSafety = $get('package_safety');
        $packageErrors = $get('package_errors');
        $finalValidation = $get('final_validation');
        $finalValidationWarnings = $get('final_validation_warnings');
        $finalValidationErrors = $get('final_validation_errors');

        $status = (string)($meta['final_status'] ?? $this->calculateFinalStatus($report));

        $lines = [
            'UltimateClans V8 -> V9 Migration Report',
            str_repeat('=', 42),
            '',
            'Conversion ID: ' . (string)($meta['token'] ?? 'unknown'),
            'Generated: ' . gmdate('Y-m-d H:i:s', (int)($meta['created_at'] ?? time())) . ' UTC',
            'Source version: ' . (string)($meta['source_version'] ?? 'unknown'),
            'V8 comparison reference: ' . (string)($meta['reference_version'] ?? 'unknown'),
            'Target version: ' . (string)($meta['target_version'] ?? 'unknown'),
            'Compatibility mode: ' . (string)($meta['compatibility_mode'] ?? 'NORMAL'),
            'Storage data migration: ' . (!array_key_exists('storage_migration', $meta) || !empty($meta['storage_migration']) ? 'ENABLED' : 'DISABLED'),
            'Legacy material conversion: ' . (!array_key_exists('material_migration', $meta) || !empty($meta['material_migration']) ? 'ENABLED' : 'DISABLED'),
            'Status: ' . $status,
            '',
            'SUMMARY',
            str_repeat('-', 42),
            'Migrated in place: ' . count($migrated),
            'Moved to V9 locations: ' . count($moved),
            'Compatibility/legacy migrations: ' . count($compatibility),
            'Known removed/obsolete: ' . count($ignored),
            'Manual review items: ' . count($unmapped),
            'Smart-preserved same-path values: ' . count($smartPreserved),
            'Safe-mode held values: ' . count($conservative),
            'Skipped addons: ' . count($addonsSkipped),
            'Language safety actions: ' . count($languageSafety),
            'SQLite migration entries: ' . count($storage),
            'SQLite warnings: ' . count($storageWarnings),
            'SQLite errors: ' . count($storageErrors),
            'Relational validation checks: ' . count($dataValidation),
            'Relational validation warnings: ' . count($dataValidationWarnings),
            'Relational validation errors: ' . count($dataValidationErrors),
            'YAML migration entries: ' . count($yamlStorage),
            'YAML warnings: ' . count($yamlStorageWarnings),
            'YAML errors: ' . count($yamlStorageErrors),
            'Material conversions: ' . count($materials),
            'Material fallbacks: ' . count($materialFallbacks),
            'Material warnings: ' . count($materialWarnings),
            'Package safety checks/actions: ' . count($packageSafety),
            'Package safety errors: ' . count($packageErrors),
            'Final validator checks: ' . count($finalValidation),
            'Final validator warnings: ' . count($finalValidationWarnings),
            'Final validator errors: ' . count($finalValidationErrors),
            'Notes/warnings: ' . count($warnings),
            '',
        ];

        $sections = [
            'MOVED SETTINGS' => $moved,
            'MIGRATED IN PLACE' => $migrated,
            'COMPATIBILITY / LEGACY MIGRATIONS' => $compatibility,
            'KNOWN REMOVED / OBSOLETE V8 SETTINGS' => $ignored,
            'SMART PRESERVATION' => $smartPreserved,
            'SAFE COMPATIBILITY MODE - HELD VALUES' => $conservative,
            'ADDONS NOT MIGRATED' => $addonsSkipped,
            'LANGUAGE SAFETY' => $languageSafety,
            'SQLITE DATA MIGRATION' => $storage,
            'SQLITE DATA WARNINGS' => $storageWarnings,
            'SQLITE DATA ERRORS' => $storageErrors,
            'RELATIONAL DATA VALIDATION' => $dataValidation,
            'RELATIONAL DATA WARNINGS' => $dataValidationWarnings,
            'RELATIONAL DATA ERRORS' => $dataValidationErrors,
            'YAML DATA MIGRATION' => $yamlStorage,
            'YAML DATA WARNINGS' => $yamlStorageWarnings,
            'YAML DATA ERRORS' => $yamlStorageErrors,
            'MATERIAL CONVERSIONS' => $materials,
            'MATERIAL FALLBACKS' => $materialFallbacks,
            'MATERIAL WARNINGS' => $materialWarnings,
            'PACKAGE SAFETY' => $packageSafety,
            'PACKAGE SAFETY ERRORS' => $packageErrors,
            'FINAL VALIDATOR' => $finalValidation,
            'FINAL VALIDATOR WARNINGS' => $finalValidationWarnings,
            'FINAL VALIDATOR ERRORS' => $finalValidationErrors,
            'NEEDS MANUAL REVIEW' => $unmapped,
            'NOTES' => $warnings,
        ];

        foreach ($sections as $title => $items) {
            $lines[] = $title;
            $lines[] = str_repeat('-', 42);
            if (!$items) {
                $lines[] = 'None.';
            } else {
                foreach (array_values($items) as $i => $item) $lines[] = sprintf('%d. %s', $i + 1, (string)$item);
            }
            $lines[] = '';
        }

        $lines[] = 'IMPORTANT';
        $lines[] = str_repeat('-', 42);
        $storageMigration = !array_key_exists('storage_migration', $meta) || !empty($meta['storage_migration']);
        if ($storageMigration) {
            $lines[] = 'This conversion included V8 configuration plus active SQLite and compatible YAML storage when detected.';
            $lines[] = 'SQLite: Core data stays in clans.db; Logger, Mail and Rewards tables are separated into their V9 module databases.';
            $lines[] = 'Relational validation checks clan leaders/members against players and detects players assigned to multiple clans.';
            $lines[] = 'YAML: Core/addon data is rebuilt in clean V9 templates when the data container is compatible. Unsafe serializer/container changes are blocked.';
        } else {
            $lines[] = 'This conversion was configuration-only. Storage data migration was disabled by the user.';
        }
        $materialMigration = !array_key_exists('material_migration', $meta) || !empty($meta['material_migration']);
        $lines[] = $materialMigration
            ? 'Legacy material names are normalized after conversion. Unsafe known legacy values fall back to the clean V9 material when possible.'
            : 'Legacy material conversion was disabled by the user.';
        $lines[] = 'Custom language files are preserved verbatim when present in the uploaded V8. Config.language and per-player language preferences are kept; custom language YAML is preserved, not schema-upgraded, so new V9 keys may still require manual completion.';
        $lines[] = 'The final package is sanitized so JARs, runtime cache/server identity files, logs and SQLite transient companion files are not distributed.';
        if (($meta['compatibility_mode'] ?? 'NORMAL') === 'SAFE_CONSERVATIVE') {
            $lines[] = 'SAFE_CONSERVATIVE mode uses V15.1 smart preservation: verified user-facing same-path values are retained, while unverified/version-sensitive values stay at clean V9 defaults.';
        }
        $lines[] = 'Final status meanings: SAFE = usable as generated; SAFE_WITH_CHANGES = structurally safe but known defaults/held values differ; REVIEW_REQUIRED = unresolved migration choices remain; UNSAFE_DATA = data/package validation error detected.';
        $lines[] = 'Do not use an UNSAFE_DATA result in production. REVIEW_REQUIRED results require the listed items to be checked before deployment.';
        $lines[] = '';

        return implode("\r\n", $lines);
    }

    private function calculateFinalStatus(array $report): string
    {
        $count = static fn(string $key): int => isset($report[$key]) && is_array($report[$key]) ? count($report[$key]) : 0;

        $fatal = $count('storage_errors')
            + $count('yaml_storage_errors')
            + $count('data_validation_errors')
            + $count('package_errors')
            + $count('final_validation_errors');
        if ($fatal > 0) return 'UNSAFE_DATA';

        $review = $count('unmapped')
            + $count('addons_skipped')
            + $count('material_fallbacks')
            + $count('material_warnings');
        if ($review > 0) return 'REVIEW_REQUIRED';

        $changes = $count('conservative')
            + $count('ignored')
            + $count('data_validation_warnings')
            + $count('final_validation_warnings');
        if ($changes > 0) return 'SAFE_WITH_CHANGES';

        return 'SAFE';
    }

    public function cleanup(): void
    {
        $dir = $this->cfg['runtime'] . '/results';
        if (is_dir($dir)) {
            foreach (glob($dir . '/*') ?: [] as $file) {
                if (is_file($file) && filemtime($file) < time() - (int)$this->cfg['result_ttl']) @unlink($file);
            }
        }
        // Remove stale crash leftovers without touching normal conversion jobs.
        $workDir = $this->cfg['runtime'] . '/work';
        if (is_dir($workDir)) {
            foreach (glob($workDir . '/*') ?: [] as $entry) {
                if (is_dir($entry) && filemtime($entry) < time() - 7200) {
                    $this->removeTree($entry);
                }
            }
        }
    }

    private function migrate(string $userRoot, string $outputRoot): void
    {
        if ($this->conservativeMode) {
            $this->report['warnings'][] = 'Safe compatibility mode enabled because V8 ' . ($this->sourceVersion ?? 'unknown') . ' is too far from the available V8 reference ' . ($this->referenceVersion ?? 'unknown') . '. V15.1 smart preservation keeps verified user-facing values while version-sensitive same-path values remain at clean V9 defaults.';
        }
        $this->migrateLegacyRoles($userRoot, $outputRoot);
        $v8Files = $this->yamlFiles($this->cfg['templates_v8']);
        foreach ($v8Files as $rel => $defaultFile) {
            $userFile = $userRoot . '/' . $rel;
            if (!is_file($userFile)) continue;
            $addon = $this->addonNameFromRel($rel);
            if ($addon !== null && !is_dir($outputRoot . '/addons/' . $addon)) continue;
            $default = YamlDocument::fromFile($defaultFile);
            $user = YamlDocument::fromFile($userFile);

            foreach (MigrationRules::dynamicSubtreeRules() as [$srcFile, $srcPath, $dstFile, $dstPath]) {
                if ($srcFile !== $rel || !$default->has($srcPath) || !$user->has($srcPath)) continue;
                if ($default->canonicalSubtree($srcPath) === $user->canonicalSubtree($srcPath)) continue;
                $this->queueSubtree($outputRoot, $dstFile, $dstPath, $user, $srcPath, $rel);
            }

            // Some addon files contain user-defined records below a stable parent
            // (for example ClanQuest Quest.<custom-id>). These IDs are data, not
            // schema keys. Preserve the whole record when its leaf schema is known
            // by the current V9 template instead of reporting every field as legacy.
            $dynamicAddonPrefixes = $this->migrateDynamicAddonSubtrees($rel, $default, $user, $outputRoot);

            foreach ($user->leafNodes() as $path => $node) {
                if ($this->coveredByDynamicAddonPrefix($path, $dynamicAddonPrefixes)) continue;
                if (!$default->has($path)) {
                    if ($this->coveredByChangedSubtree($rel, $path, $default, $user)) continue;
                    if ($this->isLegacyRolePath($rel, $path)) continue;
                    if ($path === 'version.generated') continue;

                    $manual = $this->manualReason($rel, $path);
                    if ($manual !== null) { $this->report['unmapped'][] = "{$rel}:{$path} — {$manual}"; continue; }
                    if ($this->isIgnored($rel, $path)) { $this->report['ignored'][] = "{$rel}:{$path}"; continue; }
                    if ($this->queueLegacyInsert($outputRoot, $rel, $path, $user)) continue;

                    // Compatibility fallback for older V8 releases: if the exact
                    // destination still exists in V9, preserve the user's value.
                    $destinations = $this->resolveDestinations($rel, $path, $outputRoot);
                    if ($destinations) {
                        $decision = $this->conservativeDecision($rel, $path, $destinations, $user, $outputRoot);
                        if ($decision['hold']) {
                            $this->report['conservative'][] = "{$rel}:{$path} — not auto-applied; {$decision['reason']}. Clean V9 default kept.";
                            continue;
                        }
                        foreach ($destinations as [$dstFile, $dstPath, $moved])
                            $this->queueValue($outputRoot, $dstFile, $dstPath, $user, $path, $rel, $moved, 'compatibility');
                        continue;
                    }

                    $this->reportUnmappedPath($rel, $path, 'custom/legacy V8 key');
                    continue;
                }

                if ($default->canonicalValue($path) === $user->canonicalValue($path)) continue;
                if ($this->coveredByChangedSubtree($rel, $path, $default, $user)) continue;
                if ($path === 'version.generated') continue;
                $manual = $this->manualReason($rel, $path);
                if ($manual !== null) { $this->report['unmapped'][] = "{$rel}:{$path} — {$manual}"; continue; }
                if ($this->isIgnored($rel, $path)) { $this->report['ignored'][] = "{$rel}:{$path}"; continue; }

                $destinations = $this->resolveDestinations($rel, $path, $outputRoot);
                if (!$destinations) { $this->reportUnmappedPath($rel, $path); continue; }
                $decision = $this->conservativeDecision($rel, $path, $destinations, $user, $outputRoot);
                if ($decision['hold']) {
                    $this->report['conservative'][] = "{$rel}:{$path} — not auto-applied; {$decision['reason']}. Clean V9 default kept.";
                    continue;
                }
                foreach ($destinations as [$dstFile, $dstPath, $moved]) $this->queueValue($outputRoot, $dstFile, $dstPath, $user, $path, $rel, $moved);
            }
        }

        $this->migrateNoReferenceFiles($userRoot, $outputRoot);
        $this->applyRolePermissionMappings($userRoot, $outputRoot);
        $this->applyStorageFanout($userRoot, $outputRoot);
        $this->applyOperations($outputRoot);
        if ($this->sourceVersion !== null && $this->referenceVersion !== null && $this->sourceVersion !== $this->referenceVersion) {
            $mode = $this->conservativeMode ? 'SAFE_CONSERVATIVE' : 'NORMAL';
            $this->report['warnings'][] = "The uploaded V8 is {$this->sourceVersion}, while the comparison template is {$this->referenceVersion}. Compatibility mode: {$mode}.";
        }
        $this->report['warnings'][] = 'Custom language files are preserved verbatim when present. Language selections and per-player language preferences are not normalized; custom language YAML is not schema-upgraded, so new V9 keys can still require manual completion.';
        $this->report['warnings'][] = 'Legacy V8 Discord logger/webhook integrations are not mapped into the V9 Discord module unless a deterministic equivalent exists; the old global event-webhook addon is intentionally not guessed.';
    }

    /**
     * Migrates user-created addon records whose first child key is an identifier,
     * not a fixed schema field. Returns source path prefixes fully handled here.
     *
     * @return string[]
     */
    private function reportUnmappedPath(string $rel, string $path, string $suffix = ''): void
    {
        if (str_starts_with($rel, 'addons/ClanGladiator/')) {
            $message = 'Addon ClanGladiator: V8 configuration, GUI and command structures were redesigned in V9.1. Deterministic equivalents were migrated; remaining V8-only settings were left at clean V9 defaults and require review.';
            if (!in_array($message, $this->report['unmapped'], true)) $this->report['unmapped'][] = $message;
            return;
        }
        $extra = $suffix !== '' ? ' (' . $suffix . ')' : '';
        $this->report['unmapped'][] = $rel . ':' . $path . $extra;
    }

    private function migrateDynamicAddonSubtrees(string $rel, YamlDocument $default, YamlDocument $user, string $outputRoot): array
    {
        if ($rel !== 'addons/ClanQuest/quests.yml') return [];

        $destinationFile = $outputRoot . '/' . $rel;
        if (!is_file($destinationFile)) return [];
        $destination = $this->doc($destinationFile);
        if (!$destination->has('Quest')) return [];

        $idsFrom = static function (YamlDocument $doc): array {
            $ids = [];
            foreach (array_keys($doc->leafNodes()) as $path) {
                if (preg_match('/^Quest\.([^.]+)\./', $path, $m)) $ids[$m[1]] = true;
            }
            return $ids;
        };

        $referenceIds = $idsFrom($default);
        $userIds = $idsFrom($user);
        if (!$userIds) return [];

        // Every relative leaf path present in at least one clean V9 quest is a
        // known schema path. Mission type remains part of the path deliberately:
        // a custom mission processor not represented by the V9 template is not
        // assumed to be compatible.
        $allowedRelativePaths = [];
        foreach (array_keys($destination->leafNodes()) as $path) {
            if (!preg_match('/^Quest\.[^.]+\.(.+)$/', $path, $m)) continue;
            $allowedRelativePaths[$m[1]] = true;
        }

        $validSubtrees = [];
        $handled = [];
        foreach (array_keys($userIds) as $id) {
            if (isset($referenceIds[$id])) continue;
            $prefix = 'Quest.' . $id;
            if (!$user->has($prefix) || $destination->has($prefix)) continue;

            $relative = [];
            foreach (array_keys($user->leafNodes()) as $path) {
                if (!str_starts_with($path, $prefix . '.')) continue;
                $relative[] = substr($path, strlen($prefix) + 1);
            }
            if (!$relative) continue;

            $required = ['status' => false, 'type' => false, 'item.material' => false];
            $hasMission = false;
            $compatible = true;
            foreach ($relative as $path) {
                if (array_key_exists($path, $required)) $required[$path] = true;
                if (str_starts_with($path, 'missions.')) $hasMission = true;
                if (!isset($allowedRelativePaths[$path])) {
                    $compatible = false;
                    break;
                }
            }
            if (!$compatible || in_array(false, $required, true) || !$hasMission) continue;

            $validSubtrees[] = $prefix;
            $handled[] = $prefix;
        }

        if (!$validSubtrees) return [];
        $operation = $destination->makeAppendChildSubtrees('Quest', $user, $validSubtrees);
        if ($operation === null) return [];

        $this->ops[$rel][] = $operation;
        $ids = array_map(static fn(string $path): string => substr($path, strlen('Quest.')), $validSubtrees);
        $this->report['compatibility'][] = $rel . ': ' . count($ids) . ' custom quest subtree(s) preserved as V9-compatible dynamic records (' . implode(', ', $ids) . ').';
        return $handled;
    }

    private function coveredByDynamicAddonPrefix(string $path, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix . '.')) return true;
        }
        return false;
    }

    private function shouldUseConservativeMode(?string $sourceVersion, ?string $referenceVersion): bool
    {
        return CompatibilityPolicy::mode(
            $sourceVersion,
            $referenceVersion,
            (int)($this->cfg['conservative_minor_distance'] ?? 6)
        ) === 'SAFE_CONSERVATIVE';
    }

    /**
     * @return array{hold:bool,smart:bool,reason:string}
     */
    private function conservativeDecision(string $rel, string $path, array $destinations, YamlDocument $user, string $outputRoot): array
    {
        if (!$this->conservativeMode) return ['hold' => false, 'smart' => false, 'reason' => 'normal compatibility mode'];

        if ($rel === 'storage.yml') return ['hold' => false, 'smart' => false, 'reason' => 'storage setting'];
        if ($rel === 'config.yml' && in_array($path, ['Config.language', 'Config.timezone'], true)) {
            return ['hold' => false, 'smart' => false, 'reason' => 'explicit administrator selection'];
        }
        if ($this->isExplicitMigrationRule($rel, $path)) {
            return ['hold' => false, 'smart' => false, 'reason' => 'explicit migration rule'];
        }

        if (count($destinations) !== 1) return ['hold' => false, 'smart' => false, 'reason' => 'multi-destination mapping'];
        [$dstFile, $dstPath, $moved] = $destinations[0];
        if ($moved || $dstFile !== $rel || $dstPath !== $path) {
            return ['hold' => false, 'smart' => false, 'reason' => 'mapped to a V9 destination'];
        }

        $sourceNode = $user->node($path);
        $destinationFile = $outputRoot . '/' . $dstFile;
        $destinationNode = is_file($destinationFile) ? $this->doc($destinationFile)->node($dstPath) : null;
        $policy = SmartPreservationPolicy::classify($rel, $path, $sourceNode, $destinationNode);

        if (!empty($policy['preserve'])) {
            $category = (string)($policy['category'] ?? 'verified');
            $reason = (string)($policy['reason'] ?? 'verified stable same-path value');
            $this->report['smart_preserved'][] = "{$rel}:{$path} [{$category}] — {$reason}.";
            return ['hold' => false, 'smart' => true, 'reason' => $reason];
        }

        return [
            'hold' => true,
            'smart' => false,
            'reason' => (string)($policy['reason'] ?? 'same-path value remains version-sensitive without an exact V8 reference'),
        ];
    }

    private function isExplicitMigrationRule(string $rel, string $path): bool
    {
        if (isset(MigrationRules::exactRules()[$rel . '|' . $path])) return true;

        if ($rel === 'commands.yml' && preg_match('/^Commands\.list\.clan\.([^.]+)\.(permission|cooldown|value|disabled_worlds|disabled_regions)$/', $path, $m)) {
            if (isset(MigrationRules::commandRules()[$m[1]])) return true;
        }
        if ($rel === 'commands.yml' && preg_match('/^Commands\.list\.clan\.(rival|ally)\.subcommands$/', $path)) return true;
        if ($rel === 'gui.yml' && preg_match('/^General\.clan_home\.items\.(home|banner|logger|mail|levelup|rewards|rival|ally)\.(clicksound|material|glow|texture|slot)$/', $path)) return true;

        foreach (MigrationRules::prefixRules() as [$srcFile, $srcPrefix]) {
            if ($srcFile !== $rel) continue;
            if ($path === $srcPrefix || str_starts_with($path, $srcPrefix . '.')) return true;
        }
        return false;
    }

    private function preserveLanguageSelectionAndFiles(string $userRoot, string $outputRoot): array
    {
        $supported = $this->supportedV9Languages();
        $sourceLanguage = $this->yamlScalar($userRoot . '/config.yml', 'Config.language');
        $outputFile = $outputRoot . '/config.yml';
        $outputLanguage = $this->yamlScalar($outputFile, 'Config.language');

        // Config.language is a user choice, not a capability whitelist. A value that is
        // absent from the bundled V9 language_flags can still be backed by a custom
        // Lang_XX.yml supplied by the server owner. Keep the original selection.
        if ($sourceLanguage !== null && $outputLanguage !== $sourceLanguage) {
            $this->replaceYamlScalar($outputFile, 'Config.language', $sourceLanguage);
            $outputLanguage = $sourceLanguage;
        }

        $preserved = $this->preserveCustomLanguageFiles($userRoot, $outputRoot, $supported);
        $customCore = $preserved['core_ids'];
        $copiedCount = $preserved['copied'];

        if ($sourceLanguage !== null) {
            $normalized = strtoupper(trim($sourceLanguage));
            if (in_array($normalized, $supported, true)) {
                $this->report['language_safety'][] = "Config.language: {$sourceLanguage} is a bundled V9 language and was preserved.";
            } elseif (isset($customCore[$normalized])) {
                $this->report['language_safety'][] = "Config.language: custom language {$sourceLanguage} was preserved together with its uploaded language file(s).";
            } else {
                $this->report['language_safety'][] = "Config.language: custom language {$sourceLanguage} was preserved, but no matching core Lang_{$sourceLanguage}.yml/Gui_Lang_{$sourceLanguage}.yml file was found in the uploaded V8 ZIP.";
                $this->report['unmapped'][] = "Custom language {$sourceLanguage}: selection preserved, but matching core language file was not found in the uploaded V8 ZIP.";
            }
        } elseif ($outputLanguage !== null) {
            $this->report['language_safety'][] = "Config.language: generated value {$outputLanguage} was kept unchanged.";
        }

        if ($copiedCount > 0) {
            $this->report['language_safety'][] = "Preserved {$copiedCount} custom language YAML file(s) from the uploaded V8 package. Files are copied verbatim and are not schema-upgraded.";
        }

        return [
            'supported' => $supported,
            'custom_core' => array_keys($customCore),
            'selected' => $sourceLanguage ?? $outputLanguage,
            'preserve_custom' => true,
        ];
    }

    private function preserveCustomLanguageFiles(string $userRoot, string $outputRoot, array $supported): array
    {
        $supportedMap = array_fill_keys(array_map(static fn($v) => strtoupper(trim((string)$v)), $supported), true);
        $coreIds = [];
        $copied = 0;

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($userRoot, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file->isFile()) continue;
            $ext = strtolower($file->getExtension());
            if (!in_array($ext, ['yml', 'yaml'], true)) continue;

            $filename = $file->getFilename();
            if (!preg_match('/^(?:Gui_)?Lang_([A-Za-z0-9_-]+)\.(?:yml|yaml)$/i', $filename, $m)) continue;
            $langId = strtoupper($m[1]);
            if (isset($supportedMap[$langId])) continue; // Preserve only non-bundled/custom language packs here.

            $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($userRoot) + 1));
            $destRel = $this->languageDestinationRel($rel, $outputRoot);
            if ($destRel === null) continue;

            $dest = $outputRoot . '/' . $destRel;
            @mkdir(dirname($dest), 0700, true);
            if (!copy($file->getPathname(), $dest)) {
                $this->report['warnings'][] = "Custom language file {$rel} could not be copied to the V9 result.";
                continue;
            }

            $copied++;
            if (preg_match('#^(?:language|languages)/#i', $rel)) $coreIds[$langId] = true;
            $this->report['language_safety'][] = "Custom language file preserved: {$rel} -> {$destRel}.";
        }

        return ['core_ids' => $coreIds, 'copied' => $copied];
    }

    private function languageDestinationRel(string $rel, string $outputRoot): ?string
    {
        $rel = str_replace('\\', '/', $rel);

        if (preg_match('#^(?:language|languages)/(.+)$#i', $rel, $m)) {
            return 'language/' . $m[1];
        }

        if (preg_match('#^addons/([^/]+)/(?:language|languages)/(.+)$#i', $rel, $m)) {
            $addon = $m[1];
            if (!is_dir($outputRoot . '/addons/' . $addon)) return null;
            $dir = is_dir($outputRoot . '/addons/' . $addon . '/languages') ? 'languages' : 'language';
            return 'addons/' . $addon . '/' . $dir . '/' . $m[2];
        }

        if (preg_match('#^modules/([^/]+)/(?:language|languages)/(.+)$#i', $rel, $m)) {
            $module = $m[1];
            if (!is_dir($outputRoot . '/modules/' . $module)) return null;
            $dir = is_dir($outputRoot . '/modules/' . $module . '/languages') ? 'languages' : 'language';
            return 'modules/' . $module . '/' . $dir . '/' . $m[2];
        }

        return null;
    }

    private function supportedV9Languages(): array
    {
        $file = $this->cfg['templates_v9'] . '/config.yml';
        if (!is_file($file)) return ['EN'];
        try {
            $doc = YamlDocument::fromFile($file);
            $languages = [];
            foreach (array_keys($doc->leafNodes()) as $path) {
                if (!preg_match('/^Config\.language_flags\.([^.]+)$/', $path, $m)) continue;
                $id = strtoupper($m[1]);
                if ($id !== 'DEFAULT') $languages[$id] = true;
            }
            $default = $this->yamlScalar($file, 'Config.language');
            if ($default !== null) $languages[strtoupper($default)] = true;
            return $languages ? array_keys($languages) : ['EN'];
        } catch (Throwable) {
            return ['EN'];
        }
    }

    private function yamlScalar(string $file, string $path): ?string
    {
        if (!is_file($file)) return null;
        try {
            $doc = YamlDocument::fromFile($file);
            $node = $doc->node($path);
            if (!$node) return null;
            $value = trim((string)$node['raw_value'], " \t\n\r\0\x0B'\"");
            return $value !== '' ? $value : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function replaceYamlScalar(string $file, string $path, string $value): bool
    {
        if (!is_file($file)) return false;
        try {
            $dest = YamlDocument::fromFile($file);
            $source = new YamlDocument("Value:\n  scalar: {$value}\n");
            $op = $dest->makeValueReplacement($path, $source, 'Value.scalar');
            if (!$op) return false;
            file_put_contents($file, $dest->applyOperations([$op]));
            unset($this->docCache[$file]);
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function sanitizeOutput(string $outputRoot): void
    {
        $removed = [];
        if (!is_dir($outputRoot)) return;

        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($outputRoot, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $item) {
            $rel = str_replace('\\', '/', substr($item->getPathname(), strlen($outputRoot) + 1));
            if ($item->isDir()) {
                if (preg_match('#(^|/)logs?$#i', $rel)) {
                    $this->removeTree($item->getPathname());
                    $removed[] = $rel . '/';
                }
                continue;
            }

            $name = strtolower($item->getFilename());
            $ext = strtolower($item->getExtension());
            $forbidden = in_array($ext, ['jar', 'class'], true)
                || $name === 'cache.json'
                || $name === 'server.id'
                || preg_match('/\.db-(wal|shm|journal)$/', $name)
                || preg_match('#(^|/)logs?/#i', $rel);
            if ($forbidden) {
                @unlink($item->getPathname());
                $removed[] = $rel;
            }
        }

        foreach ($removed as $rel) $this->report['package_safety'][] = "Removed runtime/binary artifact from result: {$rel}";

        $remainingForbidden = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($outputRoot, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $item) {
            if (!$item->isFile()) continue;
            $rel = str_replace('\\', '/', substr($item->getPathname(), strlen($outputRoot) + 1));
            $name = strtolower($item->getFilename());
            $ext = strtolower($item->getExtension());
            if (in_array($ext, ['jar', 'class'], true) || $name === 'cache.json' || $name === 'server.id' || preg_match('/\.db-(wal|shm|journal)$/', $name)) {
                $remainingForbidden[] = $rel;
            }
        }
        if ($remainingForbidden) {
            foreach ($remainingForbidden as $rel) $this->report['package_errors'][] = "Forbidden runtime/binary artifact remained in generated package: {$rel}";
        } else {
            $this->report['package_safety'][] = 'Final package scan: no JAR/class, runtime cache, server identity or SQLite transient companion files are present.';
        }
    }

    private function deduplicateReport(): void
    {
        foreach ($this->report as $key => $items) {
            if (!is_array($items)) continue;
            $this->report[$key] = array_values(array_unique(array_map('strval', $items)));
        }
    }

    private function resolveDestinations(string $rel, string $path, string $outputRoot): array
    {
        $key = $rel . '|' . $path;
        $exact = MigrationRules::exactRules();
        if (isset($exact[$key])) return array_map(static fn($d) => [$d[0], $d[1], $d[0] !== $rel || $d[1] !== $path], $exact[$key]);

        if ($rel === 'commands.yml' && preg_match('/^Commands\.list\.clan\.([^.]+)\.(permission|cooldown|value|disabled_worlds|disabled_regions)$/', $path, $m)) {
            $rules = MigrationRules::commandRules();
            if (isset($rules[$m[1]])) {
                [$file, $prefix] = $rules[$m[1]];
                $destinations = [[$file, $prefix . '.' . $m[2], true]];
                if ($m[1] === 'banner' && in_array($m[2], ['permission', 'cooldown'], true))
                    $destinations[] = [$file, 'Commands.banner.' . $m[2], true];
                if (in_array($m[1], ['rival', 'ally'], true) && in_array($m[2], ['permission', 'cooldown'], true))
                    $destinations[] = [$file, 'Commands.' . $m[1] . '.' . $m[2], true];
                return $destinations;
            }
        }
        if ($rel === 'commands.yml' && preg_match('/^Commands\.list\.clan\.(rival|ally)\.subcommands$/', $path, $m))
            return [['modules/Rivalally/commands.yml', 'Commands.' . $m[1] . '.subcommands', true]];

        if ($rel === 'gui.yml' && preg_match('/^General\.clan_home\.items\.(home|banner|logger|mail|levelup|rewards|rival|ally)\.(clicksound|material|glow|texture|slot)$/', $path, $m)) {
            $map = [
                'home' => ['modules/Home/config.yml', 'Config.Icon'],
                'banner' => ['modules/Banner/config.yml', 'Config.Icon'],
                'logger' => ['modules/Logger/config.yml', 'Config.Icon'],
                'mail' => ['modules/Mail/config.yml', 'Config.Icon'],
                'levelup' => ['modules/Levelup/config.yml', 'Config.Levelup.Icon'],
                'rewards' => ['modules/Rewards/config.yml', 'Config.Rewards.Icon'],
                'rival' => ['modules/Rivalally/config.yml', 'Config.Rival.Icon'],
                'ally' => ['modules/Rivalally/config.yml', 'Config.Ally.Icon'],
            ];
            [$file, $prefix] = $map[$m[1]];
            $field = $m[2] === 'clicksound' ? 'click_sound' : $m[2];
            return [[$file, $prefix . '.' . $field, true]];
        }

        $rules = MigrationRules::prefixRules();
        usort($rules, static fn($a, $b) => strlen($b[1]) <=> strlen($a[1]));
        foreach ($rules as [$srcFile, $srcPrefix, $dstFile, $dstPrefix]) {
            if ($srcFile !== $rel) continue;
            if ($path !== $srcPrefix && !str_starts_with($path, $srcPrefix . '.')) continue;
            $suffix = substr($path, strlen($srcPrefix));
            return [[$dstFile, $dstPrefix . $suffix, true]];
        }

        $dstFile = $outputRoot . '/' . $rel;
        if (is_file($dstFile)) {
            $doc = $this->doc($dstFile);
            if ($doc->has($path)) return [[$rel, $path, false]];
        }
        return [];
    }

    private function isLegacyRolePath(string $rel, string $path): bool
    {
        if ($rel !== 'moderation.yml') return false;
        foreach ($this->legacyRoles as $role)
            if ($path === "Moderation.list.{$role}" || str_starts_with($path, "Moderation.list.{$role}.")) return true;
        return false;
    }

    private function migrateLegacyRoles(string $userRoot, string $outputRoot): void
    {
        $userFile = $userRoot . '/moderation.yml';
        $destFile = $outputRoot . '/moderation.yml';
        if (!is_file($userFile) || !is_file($destFile)) return;

        $user = YamlDocument::fromFile($userFile);
        $dest = $this->doc($destFile);
        $roles = [];
        foreach (array_keys($user->leafNodes()) as $path) {
            if (preg_match('/^Moderation\.list\.([^.]+)\./', $path, $m)) $roles[$m[1]] = true;
        }

        $insert = [];
        foreach (array_keys($roles) as $role) {
            $srcPath = "Moderation.list.{$role}";
            if (!$user->has($srcPath) || $dest->has($srcPath)) continue;
            $insert[] = $srcPath;
            $this->legacyRoles[] = $role;
            $this->report['compatibility'][] = "moderation.yml:{$srcPath} -> moderation.yml:{$srcPath} (legacy/custom role preserved)";
        }

        if ($insert) {
            $op = $dest->makeInsertBeforePathSubtrees('Moderation.list.leader', $user, $insert);
            if ($op) $this->ops['moderation.yml'][] = $op;
            else $this->report['unmapped'][] = 'moderation.yml: custom/legacy roles could not be inserted into V9 Moderation.list.';
        }
    }

    private function queueLegacyInsert(string $outputRoot, string $rel, string $path, YamlDocument $user): bool
    {
        $key = $rel . '|' . $path;
        $rules = MigrationRules::legacyInsertRules();
        if (!isset($rules[$key])) return false;
        [$dstFile, $parentPath] = $rules[$key];
        $full = $outputRoot . '/' . $dstFile;
        if (!is_file($full)) return false;
        $dest = $this->doc($full);
        $op = $dest->makePrependChildFromSource($parentPath, $user, $path);
        if (!$op) return false;
        $this->ops[$dstFile][] = $op;
        $this->report['compatibility'][] = "{$rel}:{$path} -> {$dstFile}:{$parentPath}." . basename(str_replace('.', '/', $path)) . ' (legacy key preserved)';
        return true;
    }

    private function applyRolePermissionMappings(string $userRoot, string $outputRoot): void
    {
        $userFile = $userRoot . '/moderation.yml';
        if (!is_file($userFile)) return;
        $user = YamlDocument::fromFile($userFile);

        $roles = [];
        foreach (array_keys($user->leafNodes()) as $path)
            if (preg_match('/^Moderation\.list\.([^.]+)\./', $path, $m)) $roles[$m[1]] = true;

        $rules = MigrationRules::rolePermissionRules();
        $states = [];
        foreach ($rules as $perm => [$dstFile, $dstPath]) {
            $full = $outputRoot . '/' . $dstFile;
            if (!is_file($full)) continue;
            $dest = $this->doc($full);
            if (!$dest->has($dstPath)) continue;
            $current = $dest->listItems($dstPath);
            $states[$perm] = [
                'file' => $dstFile,
                'path' => $dstPath,
                'original' => $current,
                'roles' => array_fill_keys($current, true),
            ];
        }

        foreach (array_keys($roles) as $role) {
            $items = $user->listItems("Moderation.list.{$role}.default_permissions");
            foreach ($items as $item) {
                if (!preg_match('/^([A-Z0-9_\-]+)\s*:\s*(true|false|1|0|yes|no|on|off)$/i', $item, $m)) continue;
                $perm = strtoupper($m[1]);
                if (!isset($states[$perm])) continue;
                $enabled = in_array(strtolower($m[2]), ['true','1','yes','on'], true);
                if ($enabled) $states[$perm]['roles'][$role] = true;
                else unset($states[$perm]['roles'][$role]);
            }
        }

        foreach ($states as $perm => $state) {
            $values = array_keys($state['roles']);
            if ($values === $state['original']) continue;
            $full = $outputRoot . '/' . $state['file'];
            $dest = $this->doc($full);
            $op = $dest->makeListReplacement($state['path'], $values);
            if (!$op) { $this->report['unmapped'][] = "moderation.yml role permission {$perm} -> {$state['file']}:{$state['path']}"; continue; }
            $this->ops[$state['file']][] = $op;
            $this->report['compatibility'][] = "moderation.yml role permission {$perm} -> {$state['file']}:{$state['path']}";
        }
    }

    private function migrateNoReferenceFiles(string $userRoot, string $outputRoot): void
    {
        $reference = $this->yamlFiles($this->cfg['templates_v8']);
        foreach ($this->yamlFiles($userRoot) as $rel => $userFile) {
            if (isset($reference[$rel])) continue;
            $addon = $this->addonNameFromRel($rel);
            if ($addon === null) continue;
            if (is_dir($this->cfg['templates_v8'] . '/addons/' . $addon)) continue;
            $destFile = $outputRoot . '/' . $rel;
            if (!is_file($destFile)) continue;

            $user = YamlDocument::fromFile($userFile);
            $dest = $this->doc($destFile);
            $copied = 0; $missingPaths = [];
            foreach ($user->leafNodes() as $path => $node) {
                if ($path === 'version.generated' || str_contains(strtolower($path), 'language')) continue;

                // Generic addon metadata from older builds must never be copied into V9.
                if ($path === 'Config.version') {
                    $this->report['ignored'][] = "{$rel}:{$path}";
                    continue;
                }

                if (!$dest->has($path)) {
                    // ClanBank V9.1 removed the old enabled toggle. If V8 had it
                    // enabled, selecting/installing the addon in the generated V9
                    // package is the equivalent state; only an old disabled value
                    // needs an administrator decision.
                    if ($rel === 'addons/ClanBank/config.yml' && $path === 'Config.Bank.enabled') {
                        $raw = strtolower(trim((string)($node['raw_value'] ?? ''), " \t\n\r\0\x0B'\""));
                        if (in_array($raw, ['true', '1', 'yes', 'on'], true)) {
                            $this->report['ignored'][] = $rel . ':' . $path . ' (V9.1 addon presence represents enabled=true)';
                            continue;
                        }
                    }
                    $missingPaths[] = $path;
                    continue;
                }
                $this->queueValue($outputRoot, $rel, $path, $user, $path, $rel, false, 'silent');
                $copied++;
            }
            if ($copied > 0) $this->report['compatibility'][] = "{$rel}: {$copied} compatible values migrated without an exact V8 reference template.";
            if ($missingPaths) {
                $shown = array_slice($missingPaths, 0, 20);
                $details = implode(', ', $shown);
                if (count($missingPaths) > count($shown)) $details .= ', ... +' . (count($missingPaths) - count($shown)) . ' more';
                $this->report['unmapped'][] = "{$rel}: " . count($missingPaths) . " legacy addon setting(s) have no same-path V9 equivalent. Paths: {$details}.";
            }
        }
    }

    private function applyStorageFanout(string $userRoot, string $outputRoot): void
    {
        $defaultFile = $this->cfg['templates_v8'] . '/storage.yml';
        $userFile = $userRoot . '/storage.yml';
        if (!is_file($defaultFile) || !is_file($userFile)) return;
        $default = YamlDocument::fromFile($defaultFile);
        $user = YamlDocument::fromFile($userFile);

        foreach (MigrationRules::storageFanout() as $path) {
            if (!$default->has($path) || !$user->has($path) || $default->canonicalValue($path) === $user->canonicalValue($path)) continue;
            foreach (['modules/Logger/config.yml','modules/Mail/config.yml','modules/Rewards/config.yml'] as $dst)
                $this->queueValue($outputRoot, $dst, $path, $user, $path, 'storage.yml', true);
        }
    }

    private function queueValue(string $outputRoot, string $dstFile, string $dstPath, YamlDocument $source, string $srcPath, string $srcFile, bool $moved, ?string $reportBucket = null): void
    {
        $full = $outputRoot . '/' . $dstFile;
        if (!is_file($full)) { $this->report['unmapped'][] = "{$srcFile}:{$srcPath} -> missing {$dstFile}"; return; }
        $dest = $this->doc($full);
        if (!$dest->has($dstPath)) { $this->report['unmapped'][] = "{$srcFile}:{$srcPath} -> {$dstFile}:{$dstPath}"; return; }
        $op = $dest->makeValueReplacement($dstPath, $source, $srcPath);
        if (!$op) { $this->report['unmapped'][] = "{$srcFile}:{$srcPath} -> {$dstFile}:{$dstPath}"; return; }
        $this->ops[$dstFile][] = $op;
        if ($reportBucket !== 'silent') {
            $entry = "{$srcFile}:{$srcPath} -> {$dstFile}:{$dstPath}";
            $bucket = $reportBucket ?? ($moved ? 'moved' : 'migrated');
            $this->report[$bucket][] = $entry;
        }
    }

    private function queueSubtree(string $outputRoot, string $dstFile, string $dstPath, YamlDocument $source, string $srcPath, string $srcFile): void
    {
        $full = $outputRoot . '/' . $dstFile;
        if (!is_file($full)) { $this->report['unmapped'][] = "{$srcFile}:{$srcPath} -> missing {$dstFile}"; return; }
        $dest = $this->doc($full);
        if (!$dest->has($dstPath)) { $this->report['unmapped'][] = "{$srcFile}:{$srcPath} -> {$dstFile}:{$dstPath}"; return; }
        $op = $dest->makeSubtreeReplacement($dstPath, $source, $srcPath);
        if (!$op) { $this->report['unmapped'][] = "{$srcFile}:{$srcPath} -> {$dstFile}:{$dstPath}"; return; }
        $this->ops[$dstFile][] = $op;
        $this->report['moved'][] = "{$srcFile}:{$srcPath} -> {$dstFile}:{$dstPath} (subtree)";
    }

    private function coveredByChangedSubtree(string $rel, string $path, YamlDocument $default, YamlDocument $user): bool
    {
        foreach (MigrationRules::dynamicSubtreeRules() as [$srcFile, $srcPath]) {
            if ($srcFile !== $rel) continue;
            if ($path !== $srcPath && !str_starts_with($path, $srcPath . '.')) continue;
            if ($default->has($srcPath) && $user->has($srcPath) && $default->canonicalSubtree($srcPath) !== $user->canonicalSubtree($srcPath)) return true;
        }
        return false;
    }

    private function isIgnored(string $rel, string $path): bool
    {
        $full = $rel . '|' . $path;
        foreach (MigrationRules::ignoredPrefixes() as $prefix)
            if ($full === $prefix || str_starts_with($full, $prefix . '.')) return true;
        return false;
    }

    private function manualReason(string $rel, string $path): ?string
    {
        $full = $rel . '|' . $path;
        foreach (MigrationRules::manualRules() as $rule => $reason)
            if ($full === $rule || str_starts_with($full, $rule . '.')) return $reason;
        return null;
    }

    private function syncAddonSelection(string $userRoot, string $outputRoot): void
    {
        $userAddonsRoot = $userRoot . '/addons';
        $outputAddonsRoot = $outputRoot . '/addons';
        if (!is_dir($outputAddonsRoot)) return;

        $installed = [];
        if (is_dir($userAddonsRoot)) {
            foreach (scandir($userAddonsRoot) ?: [] as $name) {
                if ($name === '.' || $name === '..' || !is_dir($userAddonsRoot . '/' . $name)) continue;
                if ($this->directoryHasSupportedContent($userAddonsRoot . '/' . $name)) $installed[$name] = true;
            }
        }

        foreach (scandir($outputAddonsRoot) ?: [] as $name) {
            if ($name === '.' || $name === '..' || !is_dir($outputAddonsRoot . '/' . $name)) continue;
            if (!isset($installed[$name])) $this->removeTree($outputAddonsRoot . '/' . $name);
        }

        foreach (array_keys($installed) as $name) {
            if (!is_dir($this->cfg['templates_v9'] . '/addons/' . $name)) {
                if ($name === 'ClanDiscordIntegration' && is_dir($outputRoot . '/modules/Discord')) {
                    $this->report['addons_skipped'][] = 'Addon ClanDiscordIntegration: legacy global event-webhook integration detected. The V9 Discord module has different semantics and no deterministic equivalent for events.yml, so this addon is intentionally not auto-migrated.';
                } else {
                    $this->report['addons_skipped'][] = "Addon {$name}: V8 addon detected, but no V9 addon template is available. Configuration and storage for this addon are not included in the generated V9 package.";
                }
                continue;
            }
            if (!is_dir($this->cfg['templates_v8'] . '/addons/' . $name))
                $this->report['warnings'][] = "Addon {$name} exists in the uploaded V8 and in the V9 template, but there is no exact V8 reference template for it; same-path values will be migrated in compatibility mode.";
        }
    }

    private function directoryHasSupportedContent(string $dir): bool
    {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (!$file->isFile()) continue;
            $name = strtolower($file->getFilename());
            $ext = strtolower($file->getExtension());
            if (in_array($ext, ['yml', 'yaml', 'db'], true) || preg_match('/\.db-(wal|shm|journal)$/', $name)) return true;
        }
        return false;
    }

    private function addonNameFromRel(string $rel): ?string
    {
        return preg_match('#^addons/([^/]+)/#', $rel, $m) ? $m[1] : null;
    }

    private function applyOperations(string $outputRoot): void
    {
        foreach ($this->ops as $rel => $ops) {
            $file = $outputRoot . '/' . $rel;
            $doc = $this->doc($file);
            file_put_contents($file, $doc->applyOperations($ops));
        }
    }

    private function doc(string $file): YamlDocument
    {
        if (!isset($this->docCache[$file])) $this->docCache[$file] = YamlDocument::fromFile($file);
        return $this->docCache[$file];
    }

    private function extractSupportedFiles(string $zipFile, string $target): void
    {
        if (!class_exists('ZipArchive')) throw new RuntimeException('PHP ZipArchive extension is required.');
        $zip = new ZipArchive();
        if ($zip->open($zipFile) !== true) throw new RuntimeException('Invalid ZIP file.');
        $files = 0; $bytes = 0; $yamlFiles = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i); if (!$stat) continue;
            $name = str_replace('\\', '/', (string)$stat['name']);
            if ($name === '' || str_ends_with($name, '/')) continue;
            if (str_contains($name, '../') || str_starts_with($name, '/') || preg_match('/^[A-Za-z]:\//', $name)) continue;
            $lower = strtolower($name);
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            $isYaml = in_array($ext, ['yml','yaml'], true);
            $isSqlite = $ext === 'db' || preg_match('/\.db-(wal|shm|journal)$/', $lower);
            if (!$isYaml && !$isSqlite) continue;
            $files++; if ($isYaml) $yamlFiles++;
            $bytes += (int)($stat['size'] ?? 0);
            if ($files > $this->cfg['max_extracted_files'] || $bytes > $this->cfg['max_extracted_bytes']) { $zip->close(); throw new RuntimeException('ZIP exceeds extraction limits.'); }
            $dest = $target . '/' . ltrim($name, '/');
            @mkdir(dirname($dest), 0700, true);
            $in = $zip->getStream((string)$stat['name']);
            if (!is_resource($in)) continue;
            $out = fopen($dest, 'wb');
            if (!is_resource($out)) { fclose($in); continue; }
            stream_copy_to_stream($in, $out);
            fclose($out);
            fclose($in);
        }
        $zip->close();
        if ($yamlFiles === 0) throw new RuntimeException('No YAML configuration files were found in the ZIP.');
    }

    private function detectV8Root(string $input): string
    {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($input, FilesystemIterator::SKIP_DOTS));
        $candidates = [];
        $configFiles = 0;

        foreach ($iterator as $file) {
            if (!$file->isFile() || strtolower($file->getFilename()) !== 'config.yml') continue;
            $configFiles++;

            $configFile = $file->getPathname();
            $root = dirname($configFile);
            $version = $this->readVersion($configFile);
            $signature = $this->legacyV8Signature($root, $configFile);

            // A known V9 (or another explicit major) must never be accepted by
            // the legacy fallback merely because old files were left behind.
            if ($version !== null) {
                if (preg_match('/^8(?:\.|$)/', $version) && $signature['core']) {
                    $candidates[] = [
                        'root' => $root,
                        'score' => 1000 + $signature['score'],
                        'legacy' => false,
                    ];
                }
                continue;
            }

            // Some old/custom V8 installations have no version.generated.
            // Accept only a strong UltimateClans V8 signature, not an arbitrary
            // config.yml. This also supports ZIPs whose plugin files are directly
            // at the archive root.
            if ($signature['accepted']) {
                $candidates[] = [
                    'root' => $root,
                    'score' => $signature['score'],
                    'legacy' => true,
                ];
            }
        }

        if (!$candidates) {
            if ($configFiles === 0) {
                throw new RuntimeException('UltimateClans config.yml was not found in the ZIP. The ZIP may contain the plugin folder or the plugin files directly at its root.');
            }
            throw new RuntimeException('A config.yml was found, but the installation could not be identified as UltimateClans V8. The Exchange accepts an explicit V8 version or a strong legacy V8 structure (for example config.yml together with commands.yml/gui.yml/moderation.yml and legacy files such as gui2.yml, level.yml or banner.yml).');
        }

        usort($candidates, static function(array $a, array $b): int {
            if ($a['score'] !== $b['score']) return $b['score'] <=> $a['score'];
            return strlen((string)$a['root']) <=> strlen((string)$b['root']);
        });

        $selected = $candidates[0];
        $this->legacyStructureDetected = (bool)$selected['legacy'];
        return (string)$selected['root'];
    }

    /**
     * @return array{accepted:bool,core:bool,score:int}
     */
    private function legacyV8Signature(string $root, string $configFile): array
    {
        try {
            $doc = YamlDocument::fromFile($configFile);
        } catch (Throwable) {
            return ['accepted' => false, 'core' => false, 'score' => 0];
        }

        $score = 0;
        $coreShape = $doc->has('Config') && $doc->has('Clan');
        if ($doc->has('Plugin')) $score += 2;
        if ($doc->has('Config')) $score += 3;
        if ($doc->has('Clan')) $score += 3;
        if ($doc->has('Config.language')) $score += 1;
        if ($doc->has('Config.tag')) $score += 1;
        if ($doc->has('Clan.tag')) $score += 1;

        $companions = 0;
        foreach (['commands.yml', 'gui.yml', 'moderation.yml', 'modules.yml', 'storage.yml', 'chat.yml'] as $name) {
            if (!is_file($root . '/' . $name)) continue;
            $companions++;
            $score += 2;
        }

        $legacyMarkers = 0;
        foreach (['gui2.yml', 'level.yml', 'banner.yml'] as $name) {
            if (!is_file($root . '/' . $name)) continue;
            $legacyMarkers++;
            $score += 5;
        }

        $legacyConfigMarker = $doc->has('Config.language_repo_V8')
            || $doc->has('Config.language_repo_V7')
            || $doc->has('Config.regions.enabled');
        if ($legacyConfigMarker) $score += 4;

        $coreCandidate = $coreShape && $companions >= 3;
        $accepted = $coreCandidate
            && ($legacyMarkers >= 2 || ($legacyMarkers >= 1 && $legacyConfigMarker));

        return ['accepted' => $accepted, 'core' => $coreCandidate, 'score' => $score];
    }

    private function readVersion(string $configFile): ?string
    {
        if (!is_file($configFile)) return null;
        try {
            $doc = YamlDocument::fromFile($configFile);
            foreach (['version.generated', 'Version.generated', 'Plugin.version', 'Config.version', 'version'] as $path) {
                $node = $doc->node($path);
                if (!$node || ($node['type'] ?? 'container') === 'container') continue;
                $value = trim((string)($node['raw_value'] ?? ''), " \t\n\r\0\x0B'\"");
                if ($value !== '' && preg_match('/^\d+(?:\.\d+){0,3}(?:[-+._A-Za-z0-9]*)?$/', $value)) return $value;
            }
            return null;
        } catch (Throwable) { return null; }
    }

    private function yamlFiles(string $root): array
    {
        $out = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (!$file->isFile()) continue;
            $ext = strtolower($file->getExtension());
            if (!in_array($ext, ['yml','yaml'], true)) continue;
            $rel = str_replace('\\','/',substr($file->getPathname(), strlen($root) + 1));
            $out[$rel] = $file->getPathname();
        }
        ksort($out); return $out;
    }

    private function copyTree(string $src, string $dst): void
    {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
        foreach ($it as $item) {
            $rel = substr($item->getPathname(), strlen($src) + 1);
            $target = $dst . '/' . $rel;
            if ($item->isDir()) @mkdir($target, 0700, true); else { @mkdir(dirname($target),0700,true); copy($item->getPathname(), $target); }
        }
    }

    private function zipDirectory(string $dir, string $zipFile, string $rootName): void
    {
        if (!class_exists('ZipArchive')) throw new RuntimeException('PHP ZipArchive extension is required.');
        $zip = new ZipArchive();
        if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new RuntimeException('Could not create result ZIP.');
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::LEAVES_ONLY);
        foreach ($it as $file) {
            if (!$file->isFile()) continue;
            $rel = str_replace('\\','/',substr($file->getPathname(), strlen($dir) + 1));
            $zip->addFile($file->getPathname(), $rootName . '/' . $rel);
        }
        $zip->close();
    }

    private function ensureRuntime(): void
    {
        foreach (['uploads','work','results'] as $dir) @mkdir($this->cfg['runtime'] . '/' . $dir, 0700, true);
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) return;
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $item) $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        @rmdir($dir);
    }
}
