<?php

declare(strict_types=1);

final class YamlDocument
{
    private array $lines;
    private array $nodes = [];

    public function __construct(string $text)
    {
        $this->lines = preg_split('/\r\n|\n|\r/', $text) ?: [];
        $this->parse();
    }

    public static function fromFile(string $file): self
    {
        $text = @file_get_contents($file);
        if ($text === false) throw new RuntimeException("Unable to read YAML file: {$file}");
        return new self($text);
    }

    public function has(string $path): bool
    {
        return isset($this->nodes[$path]);
    }

    public function node(string $path): ?array
    {
        return $this->nodes[$path] ?? null;
    }

    public function leafNodes(): array
    {
        return array_filter($this->nodes, static fn(array $n): bool => $n['type'] !== 'container');
    }

    public function canonicalValue(string $path): ?string
    {
        $node = $this->node($path);
        if (!$node || $node['type'] === 'container') return null;
        return $this->canonicalNode($node);
    }

    public function canonicalSubtree(string $path): ?string
    {
        $node = $this->node($path);
        if (!$node) return null;
        $slice = array_slice($this->lines, $node['start'], $node['end'] - $node['start']);
        $out = [];
        foreach ($slice as $line) {
            $trim = trim($line);
            if ($trim === '' || str_starts_with($trim, '#')) continue;
            $out[] = rtrim($this->stripInlineComment($line));
        }
        return implode("\n", $out);
    }

    public function listItems(string $path): array
    {
        $node = $this->node($path);
        if (!$node) return [];

        if ($node['type'] === 'inline') {
            $raw = trim($node['raw_value']);
            if ($raw === '' || $raw === '[]' || $raw === '[ ]') return [];
            if (str_starts_with($raw, '[') && str_ends_with($raw, ']')) {
                $inner = trim(substr($raw, 1, -1));
                if ($inner === '') return [];
                return array_values(array_filter(array_map(static fn($v) => trim($v, " \t\n\r\0\x0B'\""), explode(',', $inner)), static fn($v) => $v !== ''));
            }
            return [];
        }

        $items = [];
        $slice = array_slice($this->lines, $node['start'] + 1, max(0, $node['end'] - $node['start'] - 1));
        foreach ($slice as $line) {
            if (!preg_match('/^\s*-\s*(.*?)\s*$/u', $line, $m)) continue;
            $value = trim($this->stripInlineComment($m[1]));
            $value = trim($value, " \t\n\r\0\x0B'\"");
            if ($value !== '') $items[] = $value;
        }
        return $items;
    }

    public function makeListReplacement(string $destPath, array $values): ?array
    {
        $dest = $this->node($destPath);
        if (!$dest) return null;
        $line = $this->lines[$dest['start']];
        $colon = $this->findKeyColon($line);
        if ($colon === null) return null;
        $prefix = substr($line, 0, $colon + 1);
        $replacement = [$prefix];
        foreach ($values as $value) $replacement[] = str_repeat(' ', $dest['indent'] + 2) . '- ' . (string)$value;
        if (!$values) $replacement = [$prefix . ' []'];
        return ['start' => $dest['start'], 'end' => $dest['end'], 'lines' => $replacement];
    }

    public function makeAppendChildSubtrees(string $destParentPath, YamlDocument $source, array $sourcePaths): ?array
    {
        $dest = $this->node($destParentPath);
        if (!$dest || $dest['type'] !== 'container' || !$sourcePaths) return null;
        $replacement = [];
        foreach ($sourcePaths as $sourcePath) {
            $src = $source->node($sourcePath);
            if (!$src) continue;
            $srcLines = array_slice($source->lines, $src['start'], $src['end'] - $src['start']);
            if (!$srcLines) continue;
            $delta = ($dest['indent'] + 2) - $src['indent'];
            foreach ($srcLines as $line) $replacement[] = $this->shiftIndent($line, $delta);
        }
        if (!$replacement) return null;
        return ['start' => $dest['end'], 'end' => $dest['end'], 'lines' => $replacement];
    }

    public function makeInsertBeforePathSubtrees(string $destPath, YamlDocument $source, array $sourcePaths): ?array
    {
        $dest = $this->node($destPath);
        if (!$dest || !$sourcePaths) return null;
        $replacement = [];
        foreach ($sourcePaths as $sourcePath) {
            $src = $source->node($sourcePath);
            if (!$src) continue;
            $srcLines = array_slice($source->lines, $src['start'], $src['end'] - $src['start']);
            while ($srcLines && (trim($srcLines[array_key_last($srcLines)]) === '' || str_starts_with(trim($srcLines[array_key_last($srcLines)]), '#'))) array_pop($srcLines);
            if (!$srcLines) continue;
            $delta = $dest['indent'] - $src['indent'];
            foreach ($srcLines as $line) $replacement[] = $this->shiftIndent($line, $delta);
        }
        if (!$replacement) return null;
        $start = $dest['start'];
        for ($i = $dest['start'] - 1; $i >= 0; $i--) {
            $trim = trim($this->lines[$i]);
            if ($trim === '') { $start = $i; continue; }
            if (str_starts_with($trim, '#') && strspn($this->lines[$i], ' ') >= $dest['indent']) { $start = $i; continue; }
            break;
        }
        return ['start' => $start, 'end' => $start, 'lines' => $replacement];
    }

    public function makePrependChildFromSource(string $destParentPath, YamlDocument $source, string $sourcePath): ?array
    {
        $dest = $this->node($destParentPath);
        $src = $source->node($sourcePath);
        if (!$dest || !$src || $dest['type'] !== 'container') return null;
        $srcLines = array_slice($source->lines, $src['start'], $src['end'] - $src['start']);
        if (!$srcLines) return null;
        $delta = ($dest['indent'] + 2) - $src['indent'];
        $replacement = [];
        foreach ($srcLines as $line) $replacement[] = $this->shiftIndent($line, $delta);
        return ['start' => $dest['start'] + 1, 'end' => $dest['start'] + 1, 'lines' => $replacement];
    }

    public function makeAppendChildFromSource(string $destParentPath, YamlDocument $source, string $sourcePath): ?array
    {
        $dest = $this->node($destParentPath);
        $src = $source->node($sourcePath);
        if (!$dest || !$src || $dest['type'] !== 'container') return null;
        $srcLines = array_slice($source->lines, $src['start'], $src['end'] - $src['start']);
        if (!$srcLines) return null;
        $delta = ($dest['indent'] + 2) - $src['indent'];
        $replacement = [];
        foreach ($srcLines as $line) $replacement[] = $this->shiftIndent($line, $delta);
        return ['start' => $dest['end'], 'end' => $dest['end'], 'lines' => $replacement];
    }

    public function makeValueReplacement(string $destPath, YamlDocument $source, string $sourcePath): ?array
    {
        $dest = $this->node($destPath);
        $src = $source->node($sourcePath);
        if (!$dest || !$src || $dest['type'] === 'container' || $src['type'] === 'container') return null;

        if ($src['type'] === 'inline') {
            $line = $this->lines[$dest['start']];
            $colon = $this->findKeyColon($line);
            if ($colon === null) return null;
            $prefix = substr($line, 0, $colon + 1);
            $suffix = $src['raw_value'] === '' ? '' : ' ' . $src['raw_value'];
            return ['start' => $dest['start'], 'end' => $dest['end'], 'lines' => [$prefix . $suffix]];
        }

        $srcLines = array_slice($source->lines, $src['start'], $src['end'] - $src['start']);
        if (!$srcLines) return null;

        $destLine = $this->lines[$dest['start']];
        $destColon = $this->findKeyColon($destLine);
        $srcColon = $this->findKeyColon($srcLines[0]);
        if ($destColon === null || $srcColon === null) return null;

        $srcSuffix = substr($srcLines[0], $srcColon + 1);
        $replacement = [substr($destLine, 0, $destColon + 1) . $srcSuffix];
        $delta = $dest['indent'] - $src['indent'];
        for ($i = 1, $c = count($srcLines); $i < $c; $i++) $replacement[] = $this->shiftIndent($srcLines[$i], $delta);

        return ['start' => $dest['start'], 'end' => $dest['end'], 'lines' => $replacement];
    }

    public function makeSubtreeReplacement(string $destPath, YamlDocument $source, string $sourcePath): ?array
    {
        $dest = $this->node($destPath);
        $src = $source->node($sourcePath);
        if (!$dest || !$src) return null;

        $srcLines = array_slice($source->lines, $src['start'], $src['end'] - $src['start']);
        if (!$srcLines) return null;

        $destLine = $this->lines[$dest['start']];
        $destColon = $this->findKeyColon($destLine);
        $srcColon = $this->findKeyColon($srcLines[0]);
        if ($destColon === null || $srcColon === null) return null;

        $replacement = [substr($destLine, 0, $destColon + 1) . substr($srcLines[0], $srcColon + 1)];
        $delta = $dest['indent'] - $src['indent'];
        for ($i = 1, $c = count($srcLines); $i < $c; $i++) $replacement[] = $this->shiftIndent($srcLines[$i], $delta);

        return ['start' => $dest['start'], 'end' => $dest['end'], 'lines' => $replacement];
    }

    public function applyOperations(array $operations): string
    {
        usort($operations, static function(array $a, array $b): int {
            if ($a['start'] === $b['start']) return $b['end'] <=> $a['end'];
            return $b['start'] <=> $a['start'];
        });

        $lines = $this->lines;
        $occupied = [];
        foreach ($operations as $op) {
            $key = $op['start'] . ':' . $op['end'];
            if (isset($occupied[$key])) continue;
            $occupied[$key] = true;
            array_splice($lines, $op['start'], $op['end'] - $op['start'], $op['lines']);
        }
        return implode("\n", $lines) . "\n";
    }

    private function parse(): void
    {
        $stack = [];
        $count = count($this->lines);

        for ($i = 0; $i < $count; $i++) {
            $line = $this->lines[$i];
            $trim = trim($line);
            if ($trim === '' || str_starts_with($trim, '#') || str_starts_with($trim, '-')) continue;

            if (!preg_match('/^( *)([^#\s][^:]*?):(?:[ \t]*(.*))?$/u', $line, $m)) continue;
            $indent = strlen($m[1]);
            $key = trim($m[2]);
            $rawValue = isset($m[3]) ? rtrim($m[3]) : '';

            while ($stack && $stack[array_key_last($stack)]['indent'] >= $indent) array_pop($stack);
            $parents = array_column($stack, 'key');
            $path = implode('.', array_merge($parents, [$key]));

            $boundary = $count;
            for ($j = $i + 1; $j < $count; $j++) {
                $nextTrim = trim($this->lines[$j]);
                if ($nextTrim === '' || str_starts_with($nextTrim, '#')) continue;
                $nextIndent = strspn($this->lines[$j], ' ');
                if ($nextIndent < $indent || ($nextIndent === $indent && !str_starts_with($nextTrim, '-'))) { $boundary = $j; break; }
            }

            $type = 'inline';
            $end = $i + 1;
            if ($rawValue === '') {
                $firstChild = null;
                for ($j = $i + 1; $j < $boundary; $j++) {
                    $t = trim($this->lines[$j]);
                    if ($t === '' || str_starts_with($t, '#')) continue;
                    $firstChild = $t;
                    break;
                }
                if ($firstChild !== null && str_starts_with($firstChild, '-')) {
                    $type = 'list';
                    $end = $boundary;
                } else {
                    $type = 'container';
                    $end = $boundary;
                }
            } elseif (($rawValue[0] ?? '') === '[' || ($rawValue[0] ?? '') === '{') {
                $balance = $this->flowBalance($rawValue);
                if ($balance > 0) {
                    $type = 'flow';
                    $end = $i + 1;
                    while ($end < $count && $balance > 0) {
                        $balance += $this->flowBalance($this->lines[$end]);
                        $end++;
                    }
                }
            }

            $this->nodes[$path] = [
                'path' => $path,
                'key' => $key,
                'indent' => $indent,
                'start' => $i,
                'end' => $end,
                'type' => $type,
                'raw_value' => $rawValue,
            ];

            if ($type === 'container') $stack[] = ['indent' => $indent, 'key' => $key];
        }
    }

    private function canonicalNode(array $node): string
    {
        if ($node['type'] === 'inline') return trim($this->stripInlineComment($node['raw_value']));
        $slice = array_slice($this->lines, $node['start'], $node['end'] - $node['start']);
        $out = [];
        foreach ($slice as $index => $line) {
            $trim = trim($line);
            if ($trim === '' || str_starts_with($trim, '#')) continue;
            if ($index === 0) {
                $colon = $this->findKeyColon($line);
                if ($colon !== null) $line = substr($line, $colon + 1);
            }
            $line = trim($this->stripInlineComment($line));
            if ($line !== '') $out[] = $line;
        }
        return implode("\n", $out);
    }

    private function stripInlineComment(string $value): string
    {
        $single = false; $double = false; $escape = false;
        $len = strlen($value);
        for ($i = 0; $i < $len; $i++) {
            $ch = $value[$i];
            if ($escape) { $escape = false; continue; }
            if ($ch === '\\' && $double) { $escape = true; continue; }
            if ($ch === "'" && !$double) { $single = !$single; continue; }
            if ($ch === '"' && !$single) { $double = !$double; continue; }
            if ($ch === '#' && !$single && !$double && ($i === 0 || ctype_space($value[$i - 1]))) return rtrim(substr($value, 0, $i));
        }
        return rtrim($value);
    }

    private function findKeyColon(string $line): ?int
    {
        $single = false; $double = false; $escape = false;
        for ($i = 0, $len = strlen($line); $i < $len; $i++) {
            $ch = $line[$i];
            if ($escape) { $escape = false; continue; }
            if ($ch === '\\' && $double) { $escape = true; continue; }
            if ($ch === "'" && !$double) { $single = !$single; continue; }
            if ($ch === '"' && !$single) { $double = !$double; continue; }
            if ($ch === ':' && !$single && !$double) return $i;
        }
        return null;
    }

    private function shiftIndent(string $line, int $delta): string
    {
        if ($delta === 0 || trim($line) === '') return $line;
        if ($delta > 0) return str_repeat(' ', $delta) . $line;
        $remove = min(-$delta, strspn($line, ' '));
        return substr($line, $remove);
    }

    private function flowBalance(string $line): int
    {
        $single = false; $double = false; $escape = false; $balance = 0;
        for ($i = 0, $len = strlen($line); $i < $len; $i++) {
            $ch = $line[$i];
            if ($escape) { $escape = false; continue; }
            if ($ch === '\\' && $double) { $escape = true; continue; }
            if ($ch === "'" && !$double) { $single = !$single; continue; }
            if ($ch === '"' && !$single) { $double = !$double; continue; }
            if ($single || $double) continue;
            if ($ch === '[' || $ch === '{') $balance++;
            if ($ch === ']' || $ch === '}') $balance--;
        }
        return $balance;
    }
}
