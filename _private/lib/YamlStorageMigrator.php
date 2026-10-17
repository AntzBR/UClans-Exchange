<?php

declare(strict_types=1);

final class YamlStorageMigrator
{
    private const CORE_FILES = [
        'storage/yaml/clans.yml',
        'storage/yaml/players.yml',
        'storage/yaml/invite.yml',
        'storage/yaml/permissions.yml',
    ];

    private const MODULE_MOVES = [
        'storage/yaml/logger.yml' => 'modules/Logger/storage/yaml/logger.yml',
        'storage/yaml/mail.yml' => 'modules/Mail/storage/yaml/mail.yml',
        'storage/yaml/rewards.yml' => 'modules/Rewards/storage/yaml/rewards.yml',
    ];

    /**
     * Clean V9 templates for these module stores use `data: [ ]` as an empty
     * placeholder, but the current V9 runtime serializers still read/write
     * records as `data.<id>.<field>` maps. Runtime shape wins over an empty
     * template placeholder when deciding whether populated V8 data is safe.
     */
    private const RUNTIME_CONTAINER_KIND = [
        'modules/Logger/storage/yaml/logger.yml' => 'map',
        'modules/Mail/storage/yaml/mail.yml' => 'map',
        'modules/Rewards/storage/yaml/rewards.yml' => 'map',
    ];

    public static function migrate(string $userRoot, string $outputRoot, string $templateRoot, array &$report): void
    {
        $report['yaml_storage'] ??= [];
        $report['yaml_storage_warnings'] ??= [];
        $report['yaml_storage_errors'] ??= [];

        $sourceFiles = self::coreYamlFiles($userRoot);
        if (!$sourceFiles) {
            self::migrateAddonFiles($userRoot, $outputRoot, $templateRoot, $report, false);
            return;
        }

        $type = self::storageType($userRoot);
        $hasData = self::filesHaveRecords($sourceFiles);

        if ($type !== null && $type !== 'yaml') {
            if ($hasData) {
                $report['yaml_storage_warnings'][] = 'YAML storage files contain data, but Storage.type is ' . $type . '; core YAML files were treated as inactive/stale and were not migrated.';
            }
            self::migrateAddonFiles($userRoot, $outputRoot, $templateRoot, $report, false);
            return;
        }

        if ($type === null && !$hasData) {
            self::migrateAddonFiles($userRoot, $outputRoot, $templateRoot, $report, false);
            return;
        }

        $report['yaml_storage'][] = 'YAML storage detected: storage/yaml/';

        foreach (self::CORE_FILES as $relative) {
            $source = $userRoot . '/' . $relative;
            if (!is_file($source)) continue;
            self::migrateDataFile($source, $templateRoot . '/' . $relative, $outputRoot . '/' . $relative, $relative, $report, true);
        }

        foreach (self::MODULE_MOVES as $sourceRelative => $destinationRelative) {
            $source = $userRoot . '/' . $sourceRelative;
            if (!is_file($source)) continue;

            $module = explode('/', $destinationRelative)[1] ?? '';
            if ($module !== '' && !is_dir($outputRoot . '/modules/' . $module)) {
                $report['yaml_storage_warnings'][] = $sourceRelative . ' contains V8 data, but module ' . $module . ' is not present in the V9 output; the file was not migrated.';
                continue;
            }

            self::migrateDataFile(
                $source,
                $templateRoot . '/' . $destinationRelative,
                $outputRoot . '/' . $destinationRelative,
                $sourceRelative . ' -> ' . $destinationRelative,
                $report,
                true,
                self::RUNTIME_CONTAINER_KIND[$destinationRelative] ?? null
            );
        }

        self::migrateAddonFiles($userRoot, $outputRoot, $templateRoot, $report, true);
    }

    private static function migrateAddonFiles(string $userRoot, string $outputRoot, string $templateRoot, array &$report, bool $coreYamlActive): void
    {
        $addonsRoot = $userRoot . '/addons';
        if (!is_dir($addonsRoot)) return;

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($addonsRoot, FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if (!$file->isFile()) continue;
            $extension = strtolower($file->getExtension());
            if ($extension !== 'yml' && $extension !== 'yaml') continue;

            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($userRoot) + 1));
            if (!preg_match('#^addons/([^/]+)/storage/yaml/[^/]+\.ya?ml$#i', $relative, $match)) continue;

            $addon = $match[1];
            if (!is_dir($outputRoot . '/addons/' . $addon)) {
                if (self::recordCountFromFile($file->getPathname()) > 0) {
                    $report['yaml_storage_warnings'][] = $relative . ' was skipped because addon ' . $addon . ' has no selected V9 output.';
                }
                continue;
            }

            $addonType = self::addonStorageType($userRoot, $addon);
            $records = self::recordCountFromFile($file->getPathname());

            if ($addonType !== null && $addonType !== 'yaml') {
                if ($records > 0) {
                    $report['yaml_storage_warnings'][] = $relative . ' contains data, but addon ' . $addon . ' is configured for ' . $addonType . ' storage; this YAML file was treated as inactive/stale.';
                }
                continue;
            }

            // If the addon does not expose a detectable storage type, migrate a
            // non-empty YAML storage file. When the core itself is in YAML mode,
            // also allow empty addon templates so the resulting tree is complete.
            if ($addonType === null && $records === 0 && !$coreYamlActive) continue;

            $template = $templateRoot . '/' . $relative;
            if (!is_file($template)) {
                if ($records > 0) $report['yaml_storage_warnings'][] = $relative . ' has no V9 YAML template and was not copied raw.';
                continue;
            }

            self::migrateDataFile($file->getPathname(), $template, $outputRoot . '/' . $relative, $relative, $report, true);
        }
    }

    private static function migrateDataFile(string $sourceFile, string $templateFile, string $destinationFile, string $label, array &$report, bool $requireCompatibleContainer = true, ?string $runtimeContainerKind = null): void
    {
        if (!is_file($templateFile)) {
            $report['yaml_storage_errors'][] = $label . ': V9 YAML template is missing.';
            return;
        }

        try {
            $sourceText = @file_get_contents($sourceFile);
            $templateText = @file_get_contents($templateFile);
            if ($sourceText === false || $templateText === false) throw new RuntimeException('unable to read source/template YAML file');
            if (str_contains($sourceText, "\0")) throw new RuntimeException('source YAML contains NUL bytes');

            $source = new YamlDocument($sourceText);
            $template = new YamlDocument($templateText);

            if (!$source->has('data')) {
                $report['yaml_storage_errors'][] = $label . ': source YAML does not contain a top-level data section.';
                return;
            }
            if (!$template->has('data')) {
                $report['yaml_storage_errors'][] = $label . ': V9 template does not contain a top-level data section.';
                return;
            }

            $records = self::recordCount($sourceText);
            $sourceKind = self::dataContainerKind($sourceText);
            $templateKind = self::dataContainerKind($templateText);
            $destinationKind = $runtimeContainerKind ?? $templateKind;

            // An empty template can be only a placeholder. When the V9 runtime
            // serializer is known, preserve/normalize according to that runtime
            // shape instead of treating the clean template syntax as a schema.
            if ($records === 0 && self::containerMismatch($sourceKind, $destinationKind)) {
                @mkdir(dirname($destinationFile), 0700, true);
                if (@file_put_contents($destinationFile, $templateText, LOCK_EX) === false) {
                    $report['yaml_storage_errors'][] = $label . ': unable to write clean V9 YAML template.';
                    return;
                }
                $report['yaml_storage'][] = $label . ': 0/0 records migrated [empty ' . ($sourceKind ?? 'unknown') . ' -> ' . ($destinationKind ?? 'unknown') . ' V9 runtime container]';
                return;
            }

            // Do not blindly transplant a populated map into a V9 list (or the
            // inverse). If a runtime serializer override is known, compare with
            // that authoritative shape; otherwise stay conservative.
            if ($requireCompatibleContainer && self::containerMismatch($sourceKind, $destinationKind)) {
                $expectedLabel = $runtimeContainerKind !== null ? 'V9 runtime serializer uses ' : 'V9 template uses ';
                $report['yaml_storage_errors'][] = $label . ': populated V8 data uses a ' . ($sourceKind ?? 'unknown') . ' container, while the ' . $expectedLabel . ($destinationKind ?? 'unknown') . '. Automatic shape conversion was blocked to prevent data corruption.';
                return;
            }

            $operation = $template->makeSubtreeReplacement('data', $source, 'data');
            if ($operation === null) {
                $report['yaml_storage_errors'][] = $label . ': unable to rebuild the V9 data section.';
                return;
            }

            $result = $template->applyOperations([$operation]);
            $check = new YamlDocument($result);
            if (!$check->has('data')) {
                $report['yaml_storage_errors'][] = $label . ': generated YAML failed structural validation.';
                return;
            }

            $sourceCanonical = $source->canonicalSubtree('data');
            $destinationCanonical = $check->canonicalSubtree('data');
            if ($sourceCanonical !== $destinationCanonical) {
                $report['yaml_storage_errors'][] = $label . ': data subtree verification failed after migration.';
                return;
            }

            @mkdir(dirname($destinationFile), 0700, true);
            if (@file_put_contents($destinationFile, $result, LOCK_EX) === false) {
                $report['yaml_storage_errors'][] = $label . ': unable to write destination YAML file.';
                return;
            }

            $countText = $records === null ? 'records migrated (count unavailable)' : $records . '/' . $records . ' records migrated';
            $kindText = $sourceKind !== null ? ' [' . $sourceKind . ' data preserved' . ($runtimeContainerKind !== null ? '; V9 runtime serializer verified' : '') . ']' : '';
            $report['yaml_storage'][] = $label . ': ' . $countText . $kindText;
        } catch (Throwable $e) {
            $report['yaml_storage_errors'][] = $label . ': ' . $e->getMessage();
        }
    }

    private static function containerMismatch(?string $sourceKind, ?string $templateKind): bool
    {
        $normalize = static function (?string $kind): ?string {
            if ($kind === null || $kind === 'empty') return null;
            return $kind;
        };

        $sourceKind = $normalize($sourceKind);
        $templateKind = $normalize($templateKind);
        return $sourceKind !== null && $templateKind !== null && $sourceKind !== $templateKind;
    }

    private static function coreYamlFiles(string $userRoot): array
    {
        $files = [];
        foreach (array_merge(self::CORE_FILES, array_keys(self::MODULE_MOVES)) as $relative) {
            $file = $userRoot . '/' . $relative;
            if (is_file($file)) $files[] = $file;
        }
        return $files;
    }

    private static function filesHaveRecords(array $files): bool
    {
        foreach ($files as $file) {
            $count = self::recordCountFromFile($file);
            if ($count === null || $count > 0) return true;
        }
        return false;
    }

    private static function recordCountFromFile(string $file): ?int
    {
        $text = @file_get_contents($file);
        return $text === false ? null : self::recordCount($text);
    }

    /**
     * Counts direct records below the top-level data key. It intentionally does
     * not deserialize YAML, so custom YAML tags/objects are never instantiated.
     */
    private static function recordCount(string $text): ?int
    {
        $lines = preg_split('/\r\n|\n|\r/', $text) ?: [];
        $dataIndex = null;
        $dataIndent = 0;
        $inline = '';

        foreach ($lines as $i => $line) {
            if (!preg_match('/^(\s*)data\s*:\s*(.*?)\s*$/u', $line, $match)) continue;
            $dataIndex = $i;
            $dataIndent = strlen(str_replace("\t", '    ', $match[1]));
            $inline = trim($match[2]);
            break;
        }

        if ($dataIndex === null) return null;
        $inlineNoComment = trim(preg_replace('/\s+#.*$/', '', $inline) ?? $inline);
        if ($inlineNoComment !== '') {
            if (in_array($inlineNoComment, ['{}', '{ }', '[]', '[ ]'], true)) return 0;
            // Flow-style populated collections are valid but counting them safely
            // without a complete YAML parser is intentionally avoided.
            return null;
        }

        $children = [];
        $minIndent = null;
        for ($i = $dataIndex + 1, $count = count($lines); $i < $count; $i++) {
            $line = $lines[$i];
            $trim = trim($line);
            if ($trim === '' || str_starts_with($trim, '#')) continue;

            $indent = strlen(str_replace("\t", '    ', substr($line, 0, strspn($line, " \t"))));
            if ($indent < $dataIndent) break;
            if ($indent === $dataIndent && !str_starts_with($trim, '-')) break;
            if ($minIndent === null || $indent < $minIndent) $minIndent = $indent;
            $children[] = [$indent, $trim];
        }

        if (!$children || $minIndent === null) return 0;

        $records = 0;
        foreach ($children as [$indent, $trim]) {
            if ($indent !== $minIndent) continue;
            if (str_starts_with($trim, '-')) {
                $records++;
                continue;
            }
            if (preg_match('/^[^#][^:]*\s*:/u', $trim)) $records++;
        }
        return $records;
    }

    private static function dataContainerKind(string $text): ?string
    {
        $lines = preg_split('/\r\n|\n|\r/', $text) ?: [];
        $dataIndex = null;
        $dataIndent = 0;

        foreach ($lines as $i => $line) {
            if (!preg_match('/^(\s*)data\s*:\s*(.*?)\s*$/u', $line, $match)) continue;
            $raw = trim($match[2]);
            if ($raw !== '') {
                if (str_starts_with($raw, '[')) return 'list';
                if (str_starts_with($raw, '{')) return 'map';
                return 'scalar/flow';
            }
            $dataIndex = $i;
            $dataIndent = strlen(str_replace("\t", '    ', $match[1]));
            break;
        }

        if ($dataIndex === null) return null;
        for ($i = $dataIndex + 1, $count = count($lines); $i < $count; $i++) {
            $line = $lines[$i];
            $trim = trim($line);
            if ($trim === '' || str_starts_with($trim, '#')) continue;
            $indent = strlen(str_replace("\t", '    ', substr($line, 0, strspn($line, " \t"))));
            if ($indent < $dataIndent) break;
            if ($indent === $dataIndent && !str_starts_with($trim, '-')) break;
            return str_starts_with($trim, '-') ? 'list' : 'map';
        }
        return 'empty';
    }

    private static function storageType(string $userRoot): ?string
    {
        return self::typeFromConfig($userRoot . '/storage.yml', ['Storage.type']);
    }

    private static function addonStorageType(string $userRoot, string $addon): ?string
    {
        $file = $userRoot . '/addons/' . $addon . '/config.yml';
        if (!is_file($file)) return null;

        try {
            $doc = YamlDocument::fromFile($file);
            foreach (['Storage.type', 'Config.Storage.type', 'Config.storage.type'] as $path) {
                $value = self::scalar($doc, $path);
                if ($value !== null) return strtolower($value);
            }
            foreach ($doc->leafNodes() as $path => $node) {
                $normalized = strtolower($path);
                if ($normalized !== 'storage.type' && !str_ends_with($normalized, '.storage.type')) continue;
                $value = trim((string)$node['raw_value'], " \t\n\r\0\x0B'\"");
                if ($value !== '') return strtolower($value);
            }
        } catch (Throwable) {}
        return null;
    }

    private static function typeFromConfig(string $file, array $paths): ?string
    {
        if (!is_file($file)) return null;
        try {
            $doc = YamlDocument::fromFile($file);
            foreach ($paths as $path) {
                $value = self::scalar($doc, $path);
                if ($value !== null) return strtolower($value);
            }
        } catch (Throwable) {}
        return null;
    }

    private static function scalar(YamlDocument $doc, string $path): ?string
    {
        $node = $doc->node($path);
        if (!$node) return null;
        $value = trim((string)$node['raw_value'], " \t\n\r\0\x0B'\"");
        return $value !== '' ? $value : null;
    }
}
