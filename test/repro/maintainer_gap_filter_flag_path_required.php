<?php
declare(strict_types=1);

// #24108 — FILTER_FLAG_PATH_REQUIRED must be 0x40000 (Zend), not 0x10000
echo 'const=', FILTER_FLAG_PATH_REQUIRED, ' hex=', dechex(FILTER_FLAG_PATH_REQUIRED), "\n";
echo 'eq262144=', FILTER_FLAG_PATH_REQUIRED === 262144 ? '1' : '0', "\n";
$named = filter_var('https://example.com', FILTER_VALIDATE_URL, FILTER_FLAG_PATH_REQUIRED);
$numeric = filter_var('https://example.com', FILTER_VALIDATE_URL, 262144);
$withPath = filter_var('https://example.com/path', FILTER_VALIDATE_URL, 262144);
echo 'named=', var_export($named, true), "\n";
echo 'numeric=', var_export($numeric, true), "\n";
echo 'with_path=', var_export($withPath, true), "\n";
