<?php
declare(strict_types=1);
foreach (['imagewebp', 'imageavif', 'imagecreatefromwebp', 'imagecreatefromavif'] as $fn) {
    echo $fn, ': ', function_exists($fn) ? 'yes' : 'no', "\n";
}
$im = imagecreatetruecolor(1, 1);
ob_start();
imagewebp($im);
$webp = ob_get_clean();
$im2 = imagecreatefromwebp('data://application/octet-stream;base64,'.base64_encode($webp));
echo $im2 ? 'webp_ok' : 'webp_fail', "\n";
ob_start();
imageavif($im);
$avif = ob_get_clean();
$im3 = imagecreatefromavif('data://application/octet-stream;base64,'.base64_encode($avif));
echo $im3 ? 'avif_ok' : 'avif_fail', "\n";
