<?php

declare(strict_types=1);

/**
 * Repro #26695 — file() missing path must E_WARNING like php-src file.c.
 */
error_clear_last();
$path = '/no/such/file_maintainer_gap_'.getmypid();
$handlerSaw = false;
set_error_handler(static function (int $no, string $str) use (&$handlerSaw): bool {
    if (E_WARNING === $no && str_contains($str, 'Failed to open stream')) {
        $handlerSaw = true;
    }

    return true;
});
$r = file($path);
restore_error_handler();
echo 'return=', var_export($r, true), PHP_EOL;
echo 'handler=', $handlerSaw ? 'yes' : 'no', PHP_EOL;

error_clear_last();
@file($path);
$e = error_get_last();
echo 'at_last=', null === $e ? 'null' : ((string) $e['type']), PHP_EOL;
echo 'at_msg=', null === $e ? 'null' : (str_contains((string) $e['message'], 'Failed to open stream') ? 'open_fail' : 'other'), PHP_EOL;

$ok = file(__FILE__);
echo 'happy=', \is_array($ok) ? 'array' : 'bad', PHP_EOL;
