<?php
declare(strict_types=1);

/**
 * Repro for #20473 — imagecolorclosesthwb() HWB nearest palette index.
 */
echo 'exists=', function_exists('imagecolorclosesthwb') ? 'yes' : 'no', PHP_EOL;

$im = imagecreate(16, 16);
imagecolorallocate($im, 0, 0, 0);
$red = imagecolorallocate($im, 255, 0, 0);
imagecolorallocate($im, 0, 255, 0);
$idx = imagecolorclosesthwb($im, 200, 10, 10);
echo 'idx=', var_export($idx, true), PHP_EOL;
echo 'is_red=', (int) ($idx === $red), PHP_EOL;

$tc = imagecreatetruecolor(4, 4);
$pack = imagecolorclosesthwb($tc, 10, 20, 30);
echo 'tc=', $pack, PHP_EOL;
echo 'tc_ok=', (int) ($pack === ((10 << 16) | (20 << 8) | 30)), PHP_EOL;

try {
    imagecolorclosesthwb($im, -1, 0, 0);
    echo "neg_ok\n";
} catch (ValueError $e) {
    echo "neg_ve\n";
}
