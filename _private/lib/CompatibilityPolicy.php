<?php

declare(strict_types=1);

final class CompatibilityPolicy
{
    public static function mode(?string $sourceVersion, ?string $referenceVersion, int $minorDistance = 6): string
    {
        if ($sourceVersion === null || $referenceVersion === null) return 'NORMAL';
        if (!preg_match('/^(\d+)\.(\d+)(?:\.(\d+))?/', $sourceVersion, $source)) return 'NORMAL';
        if (!preg_match('/^(\d+)\.(\d+)(?:\.(\d+))?/', $referenceVersion, $reference)) return 'NORMAL';
        if ((int)$source[1] !== (int)$reference[1]) return 'SAFE_CONSERVATIVE';
        $threshold = max(1, $minorDistance);
        return abs((int)$source[2] - (int)$reference[2]) >= $threshold ? 'SAFE_CONSERVATIVE' : 'NORMAL';
    }
}
