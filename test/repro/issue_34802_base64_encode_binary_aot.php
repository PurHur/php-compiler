<?php

declare(strict_types=1);

/**
 * #34802 — AOT: strict base64_decode boxes string|false; encode + data:// getimagesize.
 *
 * Prefer `$x !== false ? $x : ''` — AOT `$x === false ? '' : $x` else-arm is a broader
 * ternary defect (also hits hex2bin); Zend-observable result is identical.
 */
$header = "\x89PNG\r\n\x1a\n";
$fromDecode = base64_decode('iVBORw0KGgo=', true);
echo 'enc_literal='.base64_encode($header)."\n";
echo 'enc_roundtrip='.base64_encode($fromDecode !== false ? $fromDecode : '')."\n";

$png = base64_decode(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
    true
);
$uri = 'data://image/png;base64,'.base64_encode($png !== false ? $png : '');
$info = @getimagesize($uri);
echo 'gis=';
if (false === $info) {
    echo "false\n";
} else {
    echo (string) $info[0].'x'.(string) $info[1].':'.(string) $info['mime']."\n";
}
