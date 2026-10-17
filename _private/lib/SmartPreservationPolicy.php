<?php

declare(strict_types=1);

/**
 * Conservative-mode allowlist for settings whose V8 -> V9 semantics are
 * stable enough to preserve when the same destination path still exists.
 *
 * Unknown/functionally sensitive values are deliberately NOT approved here.
 */
final class SmartPreservationPolicy
{
    /**
     * @return array{preserve:bool,category:string,reason:string}
     */
    public static function classify(string $file, string $path, ?array $sourceNode, ?array $destinationNode): array
    {
        if ($sourceNode === null || $destinationNode === null) {
            return self::hold('missing_destination_shape', 'source/destination node shape could not be compared');
        }

        if (!self::compatibleShape($sourceNode, $destinationNode)) {
            return self::hold('shape_changed', 'source and V9 destination use different YAML value shapes');
        }

        $rule = self::matchingRule($file, $path);
        if ($rule === null) {
            return self::hold('version_sensitive', 'same-path value is not in the verified smart-preservation allowlist');
        }

        return [
            'preserve' => true,
            'category' => $rule[0],
            'reason' => $rule[1],
        ];
    }

    private static function matchingRule(string $file, string $path): ?array
    {
        if ($file === 'commands.yml') {
            if (preg_match('/^Commands\.command_aliases\.[^.]+$/', $path)) {
                return ['command_alias', 'custom command aliases are user-facing values and the V9 destination path still exists'];
            }
            if (preg_match('/^Commands\.command_main\.[^.]+$/', $path)) {
                return ['command_name', 'custom main command names are user-facing values and the V9 destination path still exists'];
            }
            if (preg_match('/^Commands\.list\.clan\.[^.]+\.sound\.on_error$/', $path)) {
                return ['command_presentation', 'command error sound display setting is presentation-only'];
            }
        }

        if ($file === 'chat.yml') {
            if (preg_match('/\.sound\.display$/', $path)) {
                return ['chat_presentation', 'sound display toggles are presentation-only'];
            }
            if (preg_match('/\.message\.center$/', $path)) {
                return ['chat_presentation', 'message centering is presentation-only'];
            }
        }

        if ($file === 'config.yml') {
            $stableExact = [
                'Config.tag',
                'Config.teleport_delay',
                'Config.player.nickname.custom_char',
                'Config.player.nickname.allowed_chars',
                'Config.player.invites.max_store',
                'Config.player.kdr.enabled',
                'Config.player.souls.enabled',
                'Clan.tag.max_chars',
                'Clan.tag.allowed_chars',
                'Clan.tag.disabled_decoration',
                'Clan.tag.default_color',
                'Clan.tag.noclan_formated',
                'Clan.tag.unicodeblock',
                'Clan.tag.start',
                'Clan.tag.end',
                'Clan.desc.allowed_chars',
                'Clan.desc.disabled_decoration',
                'Clan.desc.default_color',
                'Clan.desc.nodesc_formated',
            ];
            if (in_array($path, $stableExact, true)) {
                return ['stable_core_preference', 'verified stable user preference with an unchanged V9 destination path'];
            }
        }

        if ($file === 'gui.yml') {
            if (preg_match('/^General\.[^.]+\.opensound$/', $path)) {
                return ['gui_presentation', 'GUI open sound is presentation-only'];
            }
            if (preg_match('/^General\.[^.]+\.rows$/', $path)) {
                return ['gui_layout', 'GUI row count is a layout preference and the V9 destination path still exists'];
            }
            if (preg_match('/^General\.[^.]+\.items\..+\.(?:clicksound|material|texture|data|amount|glow|slot|mimeslots|name|lore)$/', $path)) {
                return ['gui_item', 'GUI item appearance/layout value is safe to preserve at the unchanged destination path'];
            }
        }

        if ($file === 'moderation.yml') {
            if (preg_match('/^Moderation\.list\.[^.]+\.(?:name|icon\.(?:name|lore|slot))$/', $path)) {
                return ['role_presentation', 'role display metadata is user-facing and does not change role semantics'];
            }
            if (preg_match('/^Moderation\.permissions\.[^.]+\.name$/', $path)) {
                return ['permission_display', 'permission display name is user-facing metadata'];
            }
        }

        return null;
    }

    private static function compatibleShape(array $sourceNode, array $destinationNode): bool
    {
        $srcType = (string)($sourceNode['type'] ?? '');
        $dstType = (string)($destinationNode['type'] ?? '');

        if ($srcType === 'container' || $dstType === 'container') return false;
        if ($srcType !== $dstType) return false;

        if ($srcType !== 'inline') return true;

        $srcKind = self::scalarKind((string)($sourceNode['raw_value'] ?? ''));
        $dstKind = self::scalarKind((string)($destinationNode['raw_value'] ?? ''));

        // Quoted/unquoted strings are the same semantic shape. Empty destination
        // values are also allowed because many V9 presentation fields default empty.
        if ($dstKind === 'empty') return true;
        if ($srcKind === 'string' && $dstKind === 'string') return true;
        return $srcKind === $dstKind;
    }

    private static function scalarKind(string $raw): string
    {
        $value = trim(self::stripComment($raw));
        if ($value === '' || $value === "''" || $value === '""') return 'empty';

        $unquoted = trim($value, " \t\n\r\0\x0B'\"");
        $lower = strtolower($unquoted);
        if (in_array($lower, ['true', 'false'], true)) return 'bool';
        if (preg_match('/^[+-]?\d+$/', $unquoted)) return 'int';
        if (preg_match('/^[+-]?(?:\d+\.\d*|\d*\.\d+)$/', $unquoted)) return 'float';
        if ((str_starts_with($value, '[') && str_ends_with($value, ']')) || (str_starts_with($value, '{') && str_ends_with($value, '}'))) return 'flow';
        return 'string';
    }

    private static function stripComment(string $value): string
    {
        $single = false; $double = false; $escape = false;
        for ($i = 0, $len = strlen($value); $i < $len; $i++) {
            $ch = $value[$i];
            if ($escape) { $escape = false; continue; }
            if ($ch === '\\' && $double) { $escape = true; continue; }
            if ($ch === "'" && !$double) { $single = !$single; continue; }
            if ($ch === '"' && !$single) { $double = !$double; continue; }
            if ($ch === '#' && !$single && !$double && ($i === 0 || ctype_space($value[$i - 1]))) return rtrim(substr($value, 0, $i));
        }
        return rtrim($value);
    }

    private static function hold(string $category, string $reason): array
    {
        return ['preserve' => false, 'category' => $category, 'reason' => $reason];
    }
}
