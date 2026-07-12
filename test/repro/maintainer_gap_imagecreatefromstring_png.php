<?php

declare(strict_types=1);

/**
 * Issue #6215 repro — imagecreatefromstring() PNG decode.
 */
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
var_export(function_exists('imagecreatefromstring'));
echo "\n";
$im = imagecreatefromstring($png);
echo get_class($im), "\n";
ob_start();
imagepng($im);
echo strlen(ob_get_clean()) > 8 ? "ok\n" : "fail\n";
