<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$cfg = require dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/lib/Converter.php';
(new UltimateClansConverter($cfg))->cleanup();
echo "Cleanup completed.\n";
