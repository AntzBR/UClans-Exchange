<?php

declare(strict_types=1);

require_once __DIR__ . '/YamlDocument.php';

/**
 * Normalizes legacy/invalid Bukkit material names after the V8 -> V9
 * configuration migration has finished.
 *
 * This intentionally operates only on configuration YAML files. Storage and
 * language files are excluded because a key called "material" there can be
 * user data rather than a GUI/item configuration value.
 */
final class MaterialMigrator
{
    private const COLORS = [
        0 => 'WHITE', 1 => 'ORANGE', 2 => 'MAGENTA', 3 => 'LIGHT_BLUE',
        4 => 'YELLOW', 5 => 'LIME', 6 => 'PINK', 7 => 'GRAY',
        8 => 'LIGHT_GRAY', 9 => 'CYAN', 10 => 'PURPLE', 11 => 'BLUE',
        12 => 'BROWN', 13 => 'GREEN', 14 => 'RED', 15 => 'BLACK',
    ];

    /** @var array<string,string> */
    private const SIMPLE_ALIASES = [
        // Known UltimateClans/legacy configuration spellings.
        'CAMP_FIRE' => 'CAMPFIRE',
        'DIAMONT_SWORD' => 'DIAMOND_SWORD',

        // Bukkit pre-flattening aliases that are unambiguous for item icons.
        'WORKBENCH' => 'CRAFTING_TABLE',
        'WATCH' => 'CLOCK',
        'EXP_BOTTLE' => 'EXPERIENCE_BOTTLE',
        'FIREWORK' => 'FIREWORK_ROCKET',
        'FIREWORK_CHARGE' => 'FIREWORK_STAR',
        'MOB_SPAWNER' => 'SPAWNER',
        'ENDER_PORTAL_FRAME' => 'END_PORTAL_FRAME',
        'REDSTONE_COMPARATOR' => 'COMPARATOR',
        'REDSTONE_COMPARATOR_OFF' => 'COMPARATOR',
        'REDSTONE_COMPARATOR_ON' => 'COMPARATOR',
        'DIODE' => 'REPEATER',
        'DIODE_BLOCK_OFF' => 'REPEATER',
        'DIODE_BLOCK_ON' => 'REPEATER',
        'IRON_FENCE' => 'IRON_BARS',
        'THIN_GLASS' => 'GLASS_PANE',
        'NETHER_FENCE' => 'NETHER_BRICK_FENCE',
        'NETHER_BRICK_ITEM' => 'NETHER_BRICK',
        'SNOW_BALL' => 'SNOWBALL',
        'CLAY_BRICK' => 'BRICK',
        'HARD_CLAY' => 'TERRACOTTA',
        'MYCEL' => 'MYCELIUM',
        'SOIL' => 'FARMLAND',
        'CROPS' => 'WHEAT',
        'SEEDS' => 'WHEAT_SEEDS',
        'SULPHUR' => 'GUNPOWDER',
        'PORK' => 'PORKCHOP',
        'GRILLED_PORK' => 'COOKED_PORKCHOP',
        'RAW_BEEF' => 'BEEF',
        'RAW_CHICKEN' => 'CHICKEN',
        'WOOD_DOOR' => 'OAK_DOOR',
        'WOOD_BUTTON' => 'OAK_BUTTON',
        'WOOD_PLATE' => 'OAK_PRESSURE_PLATE',
        'STONE_PLATE' => 'STONE_PRESSURE_PLATE',
        'IRON_PLATE' => 'HEAVY_WEIGHTED_PRESSURE_PLATE',
        'GOLD_PLATE' => 'LIGHT_WEIGHTED_PRESSURE_PLATE',
        'SIGN' => 'OAK_SIGN',
        'WALL_SIGN' => 'OAK_WALL_SIGN',
        'GRASS' => 'GRASS_BLOCK',
        'CACTUS_GREEN' => 'GREEN_DYE',
        'DANDELION_YELLOW' => 'YELLOW_DYE',
        'ROSE_RED' => 'RED_DYE',

        'WOOD_SWORD' => 'WOODEN_SWORD',
        'WOOD_AXE' => 'WOODEN_AXE',
        'WOOD_PICKAXE' => 'WOODEN_PICKAXE',
        'WOOD_HOE' => 'WOODEN_HOE',
        'WOOD_SPADE' => 'WOODEN_SHOVEL',
        'STONE_SPADE' => 'STONE_SHOVEL',
        'IRON_SPADE' => 'IRON_SHOVEL',
        'GOLD_SPADE' => 'GOLDEN_SHOVEL',
        'DIAMOND_SPADE' => 'DIAMOND_SHOVEL',
        'GOLD_SWORD' => 'GOLDEN_SWORD',
        'GOLD_AXE' => 'GOLDEN_AXE',
        'GOLD_PICKAXE' => 'GOLDEN_PICKAXE',
        'GOLD_HOE' => 'GOLDEN_HOE',
    ];

    public static function migrate(string $outputRoot, string $v9TemplateRoot, array &$report): void
    {
        $report['materials'] ??= [];
        $report['material_fallbacks'] ??= [];
        $report['material_warnings'] ??= [];

        foreach (self::yamlFiles($outputRoot) as $rel => $file) {
            if (self::excluded($rel)) continue;

            $text = @file_get_contents($file);
            if ($text === false) continue;

            $doc = new YamlDocument($text);
            $ops = [];
            $templateFile = rtrim($v9TemplateRoot, '/') . '/' . $rel;
            $template = is_file($templateFile) ? YamlDocument::fromFile($templateFile) : null;

            foreach ($doc->leafNodes() as $path => $node) {
                if (!preg_match('/(?:^|\.)material$/i', $path)) continue;
                if (($node['type'] ?? '') !== 'inline') continue;

                $raw = self::scalar($doc->canonicalValue($path));
                if ($raw === '' || self::dynamicValue($raw)) continue;

                $original = strtoupper($raw);
                $hadLegacyPrefix = str_starts_with($original, 'LEGACY_');
                $legacy = $hadLegacyPrefix ? substr($original, 7) : $original;
                $dataPath = preg_replace('/material$/i', 'data', $path) ?: '';
                $data = self::integerValue($doc, $dataPath);

                [$target, $usesData, $knownLegacy] = self::resolve($legacy, $data);

                if ($hadLegacyPrefix && !$knownLegacy) {
                    $report['material_warnings'][] = "{$rel}:{$path} — {$original} uses the Bukkit LEGACY_ namespace, but no safe automatic mapping is registered. Value was preserved.";
                    continue;
                }

                if ($target !== null && $target !== $original) {
                    $op = self::scalarReplacement($doc, $path, $target);
                    if ($op) {
                        $ops[] = $op;
                        if ($usesData && $dataPath !== '' && $doc->has($dataPath)) {
                            $dataOp = self::scalarReplacement($doc, $dataPath, '0');
                            if ($dataOp) $ops[] = $dataOp;
                        }
                        $suffix = $usesData ? ' + data:' . ($data ?? 'missing') : '';
                        $report['materials'][] = "{$rel}:{$path} — {$original}{$suffix} -> {$target}";
                    }
                    continue;
                }

                if (!$knownLegacy) continue;

                // A recognized legacy family with missing/invalid data is unsafe.
                // Prefer the clean V9 value at this exact path when available.
                $fallback = $template ? self::scalar($template->canonicalValue($path)) : '';
                $fallbackUpper = strtoupper($fallback);
                if ($fallbackUpper !== '' && !self::dynamicValue($fallbackUpper)) {
                    [$fixedFallback] = self::resolve($fallbackUpper, self::integerValue($template, $dataPath));
                    if ($fixedFallback !== null) $fallbackUpper = $fixedFallback;
                    $op = self::scalarReplacement($doc, $path, $fallbackUpper);
                    if ($op) {
                        $ops[] = $op;
                        if ($dataPath !== '' && $doc->has($dataPath)) {
                            $dataOp = self::scalarReplacement($doc, $dataPath, '0');
                            if ($dataOp) $ops[] = $dataOp;
                        }
                        $report['material_fallbacks'][] = "{$rel}:{$path} — could not safely convert {$original}" . ($data !== null ? " + data:{$data}" : '') . "; V9 default used: {$fallbackUpper}";
                        continue;
                    }
                }

                $report['material_warnings'][] = "{$rel}:{$path} — recognized legacy material {$original}" . ($data !== null ? " + data:{$data}" : '') . ' could not be converted safely and no V9 default was available.';
            }

            if ($ops) file_put_contents($file, $doc->applyOperations($ops));
        }
    }

    /** @return array{0:?string,1:bool,2:bool} target, usesData, knownLegacy */
    private static function resolve(string $material, ?int $data): array
    {
        if (isset(self::SIMPLE_ALIASES[$material])) return [self::SIMPLE_ALIASES[$material], false, true];

        if (in_array($material, ['WOOL', 'STAINED_GLASS', 'STAINED_GLASS_PANE', 'STAINED_CLAY', 'CARPET'], true)) {
            if ($data === null || $data < 0 || $data > 15 || !isset(self::COLORS[$data])) return [null, true, true];
            $color = self::COLORS[$data];
            $suffix = match ($material) {
                'WOOL' => 'WOOL',
                'STAINED_GLASS' => 'STAINED_GLASS',
                'STAINED_GLASS_PANE' => 'STAINED_GLASS_PANE',
                'STAINED_CLAY' => 'TERRACOTTA',
                'CARPET' => 'CARPET',
            };
            return ["{$color}_{$suffix}", true, true];
        }

        if ($material === 'INK_SACK') {
            if ($data === null) return [null, true, true];
            $map = [
                0 => 'INK_SAC', 1 => 'RED_DYE', 2 => 'GREEN_DYE', 3 => 'COCOA_BEANS',
                4 => 'LAPIS_LAZULI', 5 => 'PURPLE_DYE', 6 => 'CYAN_DYE', 7 => 'LIGHT_GRAY_DYE',
                8 => 'GRAY_DYE', 9 => 'PINK_DYE', 10 => 'LIME_DYE', 11 => 'YELLOW_DYE',
                12 => 'LIGHT_BLUE_DYE', 13 => 'MAGENTA_DYE', 14 => 'ORANGE_DYE', 15 => 'BONE_MEAL',
            ];
            return [($data >= 0 && $data <= 15) ? ($map[$data] ?? null) : null, true, true];
        }

        if ($material === 'SKULL_ITEM') {
            if ($data === null) return [null, true, true];
            $map = [0 => 'SKELETON_SKULL', 1 => 'WITHER_SKELETON_SKULL', 2 => 'ZOMBIE_HEAD', 3 => 'PLAYER_HEAD', 4 => 'CREEPER_HEAD', 5 => 'DRAGON_HEAD'];
            return [$map[$data] ?? null, true, true];
        }

        if ($material === 'WOOD') {
            if ($data === null) return ['OAK_PLANKS', false, true];
            $map = [0 => 'OAK_PLANKS', 1 => 'SPRUCE_PLANKS', 2 => 'BIRCH_PLANKS', 3 => 'JUNGLE_PLANKS', 4 => 'ACACIA_PLANKS', 5 => 'DARK_OAK_PLANKS'];
            return [$map[$data & 7] ?? null, true, true];
        }

        if ($material === 'WOOD_STEP') {
            if ($data === null) return ['OAK_SLAB', false, true];
            $map = [0 => 'OAK_SLAB', 1 => 'SPRUCE_SLAB', 2 => 'BIRCH_SLAB', 3 => 'JUNGLE_SLAB', 4 => 'ACACIA_SLAB', 5 => 'DARK_OAK_SLAB'];
            return [$map[$data & 7] ?? null, true, true];
        }

        if ($material === 'SAPLING') {
            if ($data === null) return ['OAK_SAPLING', false, true];
            $map = [0 => 'OAK_SAPLING', 1 => 'SPRUCE_SAPLING', 2 => 'BIRCH_SAPLING', 3 => 'JUNGLE_SAPLING', 4 => 'ACACIA_SAPLING', 5 => 'DARK_OAK_SAPLING'];
            return [$map[$data & 7] ?? null, true, true];
        }

        if ($material === 'LOG') {
            if ($data === null) return ['OAK_LOG', false, true];
            $map = [0 => 'OAK_LOG', 1 => 'SPRUCE_LOG', 2 => 'BIRCH_LOG', 3 => 'JUNGLE_LOG'];
            return [$map[$data & 3] ?? null, true, true];
        }

        if ($material === 'LOG_2') {
            if ($data === null) return ['ACACIA_LOG', false, true];
            $map = [0 => 'ACACIA_LOG', 1 => 'DARK_OAK_LOG'];
            return [$map[$data & 3] ?? null, true, true];
        }

        if ($material === 'LEAVES') {
            if ($data === null) return ['OAK_LEAVES', false, true];
            $map = [0 => 'OAK_LEAVES', 1 => 'SPRUCE_LEAVES', 2 => 'BIRCH_LEAVES', 3 => 'JUNGLE_LEAVES'];
            return [$map[$data & 3] ?? null, true, true];
        }

        if ($material === 'LEAVES_2') {
            if ($data === null) return ['ACACIA_LEAVES', false, true];
            $map = [0 => 'ACACIA_LEAVES', 1 => 'DARK_OAK_LEAVES'];
            return [$map[$data & 3] ?? null, true, true];
        }

        if ($material === 'SMOOTH_BRICK') {
            if ($data === null) return ['STONE_BRICKS', false, true];
            $map = [0 => 'STONE_BRICKS', 1 => 'MOSSY_STONE_BRICKS', 2 => 'CRACKED_STONE_BRICKS', 3 => 'CHISELED_STONE_BRICKS'];
            return [$map[$data & 3] ?? null, true, true];
        }

        if ($material === 'RAW_FISH') {
            if ($data === null) return ['COD', false, true];
            $map = [0 => 'COD', 1 => 'SALMON', 2 => 'TROPICAL_FISH', 3 => 'PUFFERFISH'];
            return [$map[$data & 3] ?? null, true, true];
        }

        if ($material === 'COOKED_FISH') {
            if ($data === null) return ['COOKED_COD', false, true];
            $map = [0 => 'COOKED_COD', 1 => 'COOKED_SALMON'];
            return [$map[$data & 3] ?? null, true, true];
        }

        return [$material, false, false];
    }

    private static function integerValue(?YamlDocument $doc, string $path): ?int
    {
        if (!$doc || $path === '' || !$doc->has($path)) return null;
        $value = self::scalar($doc->canonicalValue($path));
        return preg_match('/^-?\d+$/', $value) ? (int)$value : null;
    }

    private static function scalar(?string $value): string
    {
        if ($value === null) return '';
        $value = trim($value);
        if (strlen($value) >= 2 && (($value[0] === '"' && $value[-1] === '"') || ($value[0] === "'" && $value[-1] === "'")))
            $value = substr($value, 1, -1);
        return trim($value);
    }

    private static function dynamicValue(string $value): bool
    {
        return str_contains($value, '%') || str_contains($value, '{') || str_contains($value, '}') || str_contains($value, '<') || str_contains($value, '>');
    }

    private static function scalarReplacement(YamlDocument $doc, string $path, string $value): ?array
    {
        $source = new YamlDocument("value: {$value}\n");
        return $doc->makeValueReplacement($path, $source, 'value');
    }

    private static function excluded(string $rel): bool
    {
        $r = strtolower(str_replace('\\', '/', $rel));
        return str_contains($r, '/storage/') || str_starts_with($r, 'storage/') || str_contains($r, '/lang/') || str_contains($r, '/languages/') || str_starts_with($r, 'lang/') || str_starts_with($r, 'languages/');
    }

    /** @return array<string,string> */
    private static function yamlFiles(string $root): array
    {
        $out = [];
        if (!is_dir($root)) return $out;
        $base = rtrim(str_replace('\\', '/', $root), '/');
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (!$file->isFile()) continue;
            $ext = strtolower($file->getExtension());
            if (!in_array($ext, ['yml', 'yaml'], true)) continue;
            $full = str_replace('\\', '/', $file->getPathname());
            $rel = ltrim(substr($full, strlen($base)), '/');
            $out[$rel] = $file->getPathname();
        }
        ksort($out);
        return $out;
    }
}
