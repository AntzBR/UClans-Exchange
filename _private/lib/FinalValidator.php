<?php

declare(strict_types=1);

require_once __DIR__ . '/YamlDocument.php';

/**
 * Post-migration semantic/package checks that run on the generated V9 tree.
 * The validator never rewrites user data; it reports actionable issues.
 */
final class FinalValidator
{
    public static function validate(string $outputRoot, array &$report, array $supportedLanguages = [], ?string $templateRoot = null): void
    {
        $report['final_validation'] ??= [];
        $report['final_validation_warnings'] ??= [];
        $report['final_validation_errors'] ??= [];

        self::validateRequiredFiles($outputRoot, $report);
        self::validateYamlTree($outputRoot, $report);
        self::validateLegacyRootFiles($outputRoot, $report);
        self::validateCommandAliases($outputRoot, $report);
        self::validateRoleReferences($outputRoot, $report, $templateRoot);
        self::validateLanguageSelection($outputRoot, $supportedLanguages, $report);
        self::validatePackageTree($outputRoot, $report);
    }

    private static function validateRequiredFiles(string $root, array &$report): void
    {
        $required = ['config.yml', 'commands.yml', 'gui.yml', 'admin_gui.yml', 'moderation.yml', 'storage.yml', 'modules.yml'];
        $missing = [];
        foreach ($required as $rel) if (!is_file($root . '/' . $rel)) $missing[] = $rel;

        if ($missing) {
            $report['final_validation_errors'][] = 'Missing required V9 file(s): ' . implode(', ', $missing) . '.';
        } else {
            $report['final_validation'][] = 'Required V9 core configuration files are present.';
        }
    }

    private static function validateYamlTree(string $root, array &$report): void
    {
        $count = 0;
        $errors = 0;
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (!$file->isFile()) continue;
            $ext = strtolower($file->getExtension());
            if (!in_array($ext, ['yml', 'yaml'], true)) continue;
            $count++;
            $rel = self::relative($root, $file->getPathname());
            $text = @file_get_contents($file->getPathname());
            if ($text === false) {
                $errors++;
                $report['final_validation_errors'][] = $rel . ': could not be read during final YAML validation.';
                continue;
            }
            if (preg_match('/^(?: +)?\t/m', $text)) {
                $errors++;
                $report['final_validation_errors'][] = $rel . ': tab character detected in YAML indentation.';
                continue;
            }
            try {
                $doc = new YamlDocument($text);
                // Empty custom language files are legal YAML but useless in production.
                if (preg_match('/(?:^|\/)(?:Gui_)?Lang_[^\/]+\.(?:yml|yaml)$/i', $rel) && count($doc->leafNodes()) === 0) {
                    $report['final_validation_warnings'][] = $rel . ': custom language file is empty or contains no parseable scalar/list values.';
                }
            } catch (Throwable $e) {
                $errors++;
                $report['final_validation_errors'][] = $rel . ': YAML validation failed: ' . $e->getMessage();
            }
        }

        if ($errors === 0) {
            $report['final_validation'][] = 'Final YAML tree validation parsed ' . $count . ' YAML file(s) without structural read/indentation errors.';
        }
    }

    private static function validateLegacyRootFiles(string $root, array &$report): void
    {
        $legacy = [];
        foreach (['gui2.yml', 'level.yml', 'banner.yml'] as $name) {
            if (is_file($root . '/' . $name)) $legacy[] = $name;
        }
        if ($legacy) {
            $report['final_validation_warnings'][] = 'Legacy V8 root file(s) remain in the generated package: ' . implode(', ', $legacy) . '.';
        } else {
            $report['final_validation'][] = 'No legacy V8-only root configuration files remain in the generated package.';
        }
    }

    private static function validateCommandAliases(string $root, array &$report): void
    {
        $file = $root . '/commands.yml';
        if (!is_file($file)) return;
        try { $doc = YamlDocument::fromFile($file); }
        catch (Throwable $e) { return; }

        $aliases = [];
        foreach ($doc->leafNodes() as $path => $node) {
            if (!preg_match('/^Commands\.command_aliases\.([^.]+)$/', $path, $m)) continue;
            $value = self::scalarValue((string)($node['raw_value'] ?? ''));
            if ($value === '') continue;
            $aliases[strtolower($value)][] = $m[1];
        }

        $duplicates = 0;
        foreach ($aliases as $alias => $commands) {
            $commands = array_values(array_unique($commands));
            if (count($commands) < 2) continue;
            $duplicates++;
            $report['final_validation_warnings'][] = 'Command alias collision: "' . $alias . '" is assigned to ' . implode(', ', $commands) . '.';
        }
        if ($duplicates === 0) $report['final_validation'][] = 'Command alias collision check: OK.';
    }

    private static function validateRoleReferences(string $root, array &$report, ?string $templateRoot = null): void
    {
        $moderation = $root . '/moderation.yml';
        if (!is_file($moderation)) return;
        try { $moderationDoc = YamlDocument::fromFile($moderation); }
        catch (Throwable $e) { return; }

        $roles = [];
        foreach (array_keys($moderationDoc->leafNodes()) as $path) {
            if (preg_match('/^Moderation\.list\.([^.]+)\./', $path, $m)) $roles[strtolower($m[1])] = true;
        }
        if (!$roles) return;

        $unknown = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (!$file->isFile() || !in_array(strtolower($file->getExtension()), ['yml', 'yaml'], true)) continue;
            $rel = self::relative($root, $file->getPathname());
            if ($rel === 'moderation.yml') continue;
            try { $doc = YamlDocument::fromFile($file->getPathname()); }
            catch (Throwable $e) { continue; }
            foreach ($doc->leafNodes() as $path => $node) {
                if (!str_ends_with(strtolower($path), '.applicable_roles')) continue;
                foreach ($doc->listItems($path) as $role) {
                    $id = strtolower(trim((string)$role));
                    if ($id === '' || isset($roles[$id])) continue;

                    // Do not report references that are already part of the clean V9
                    // template. They may be interpreted by the plugin as built-in
                    // permission tiers even when absent from Moderation.list.
                    if ($templateRoot !== null) {
                        $templateFile = rtrim($templateRoot, '/\\') . '/' . $rel;
                        if (is_file($templateFile)) {
                            try {
                                $templateDoc = YamlDocument::fromFile($templateFile);
                                $templateRoles = array_map('strtolower', $templateDoc->listItems($path));
                                if (in_array($id, $templateRoles, true)) continue;
                            } catch (Throwable $e) {
                                // Fall through and report the migrated reference.
                            }
                        }
                    }

                    $unknown[$rel . ':' . $path . ':' . $role] = [$rel, $path, $role];
                }
            }
        }

        if (!$unknown) {
            $report['final_validation'][] = 'Module applicable_roles references match roles available in moderation.yml.';
            return;
        }
        foreach ($unknown as [$rel, $path, $role]) {
            $report['final_validation_warnings'][] = $rel . ':' . $path . ' references role "' . $role . '" which was not found under Moderation.list.';
        }
    }

    private static function validateLanguageSelection(string $root, array $supportedLanguages, array &$report): void
    {
        $config = $root . '/config.yml';
        if (!is_file($config)) return;
        try { $doc = YamlDocument::fromFile($config); }
        catch (Throwable $e) { return; }
        $node = $doc->node('Config.language');
        if (!$node) return;
        $selected = strtoupper(self::scalarValue((string)($node['raw_value'] ?? '')));
        if ($selected === '') return;

        $supported = array_fill_keys(array_map(static fn($v) => strtoupper(trim((string)$v)), $supportedLanguages), true);
        if (isset($supported[$selected])) {
            $report['final_validation'][] = 'Selected language ' . $selected . ' is included in the V9 bundled language set.';
            return;
        }

        $matches = self::languageFiles($root, $selected);
        if ($matches) {
            $report['final_validation'][] = 'Selected custom language ' . $selected . ' has ' . count($matches) . ' preserved core language file(s).';
        } else {
            $report['final_validation_warnings'][] = 'Selected custom language ' . $selected . ' has no matching preserved core Lang_' . $selected . '.yml / Gui_Lang_' . $selected . '.yml file.';
        }
    }

    private static function languageFiles(string $root, string $id): array
    {
        $matches = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (!$file->isFile()) continue;
            if (!preg_match('/^(?:Gui_)?Lang_' . preg_quote($id, '/') . '\.(?:yml|yaml)$/i', $file->getFilename())) continue;
            $rel = self::relative($root, $file->getPathname());
            // Core language only: ignore addon/module copies for validating Config.language.
            if (str_starts_with($rel, 'addons/') || str_starts_with($rel, 'modules/')) continue;
            $matches[] = $rel;
        }
        return $matches;
    }

    private static function validatePackageTree(string $root, array &$report): void
    {
        $blocked = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            $rel = self::relative($root, $file->getPathname());
            $lower = strtolower($rel);
            if ($file->isDir()) continue;
            if (preg_match('/\.(?:jar|class|db-wal|db-shm|db-journal)$/i', $rel)
                || basename($lower) === 'cache.json'
                || basename($lower) === 'server.id'
                || preg_match('#(^|/)logs?/#i', $lower)) {
                $blocked[] = $rel;
            }
        }
        if ($blocked) {
            foreach ($blocked as $rel) $report['final_validation_errors'][] = 'Forbidden runtime/binary file survived final package sanitization: ' . $rel;
        } else {
            $report['final_validation'][] = 'Final package validator found no forbidden JAR/class/runtime identity/cache/SQLite companion files.';
        }
    }

    private static function scalarValue(string $raw): string
    {
        $raw = trim($raw);
        if (($raw[0] ?? '') === "'" && str_ends_with($raw, "'")) return trim(substr($raw, 1, -1));
        if (($raw[0] ?? '') === '"' && str_ends_with($raw, '"')) return trim(substr($raw, 1, -1));
        return trim(preg_replace('/\s+#.*$/', '', $raw) ?? $raw);
    }

    private static function relative(string $root, string $path): string
    {
        return ltrim(str_replace('\\', '/', substr($path, strlen(rtrim($root, '/\\')))), '/');
    }
}
