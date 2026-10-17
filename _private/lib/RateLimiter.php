<?php

declare(strict_types=1);

final class ExchangeRateLimiter
{
    public static function record(array $cfg, string $client): void
    {
        $runtime = (string) $cfg['runtime'];
        if (!is_dir($runtime) && !mkdir($runtime, 0700, true) && !is_dir($runtime)) {
            throw new RuntimeException('Conversion service storage is unavailable.');
        }
        $handle = fopen($runtime . '/rate-limits.json', 'c+');
        if (!$handle) throw new RuntimeException('Conversion service storage is unavailable.');
        try {
            if (!flock($handle, LOCK_EX)) {
                throw new RuntimeException('Conversion service is temporarily unavailable.');
            }
            $raw = stream_get_contents($handle);
            $data = json_decode(is_string($raw) ? $raw : '', true);
            if (!is_array($data)) $data = [];
            $now = time();
            $clientWindow = (int) $cfg['rate_limit_client_window'];
            $globalWindow = (int) $cfg['rate_limit_global_window'];
            $clientMax = (int) $cfg['rate_limit_client_max'];
            $globalMax = (int) $cfg['rate_limit_global_max'];
            $fingerprint = hash('sha256', $client);
            $all = array_values(array_filter((array) ($data['global'] ?? []),
                static fn($t): bool => is_int($t) && $t > $now - $globalWindow));
            $clients = (array) ($data['clients'] ?? []);
            foreach ($clients as $key => $values) {
                if (!is_string($key) || !is_array($values)) { unset($clients[$key]); continue; }
                $clients[$key] = array_values(array_filter($values,
                    static fn($t): bool => is_int($t) && $t > $now - $clientWindow));
                if (!$clients[$key]) unset($clients[$key]);
            }
            $clientHistory = $clients[$fingerprint] ?? [];
            if (count($clientHistory) >= $clientMax || count($all) >= $globalMax) {
                throw new RuntimeException('Too many conversions in a short period. Please try again later.');
            }
            $clientHistory[] = $now;
            $all[] = $now;
            $clients[$fingerprint] = $clientHistory;
            $payload = json_encode(['global' => $all, 'clients' => $clients], JSON_THROW_ON_ERROR);
            rewind($handle);
            if (!ftruncate($handle, 0) || fwrite($handle, $payload) !== strlen($payload) || !fflush($handle)) {
                throw new RuntimeException('Conversion service storage is unavailable.');
            }
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
