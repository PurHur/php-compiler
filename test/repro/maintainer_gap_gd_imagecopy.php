<?php
declare(strict_types=1);

$src = imagecreatetruecolor(4, 4);
$dst = imagecreatetruecolor(8, 8);
$white = imagecolorallocate($src, 255, 255, 255);
imagefill($src, 0, 0, $white);
var_export(function_exists('imagecopy'));
echo "\n";
imagecopy($dst, $src, 2, 2, 0, 0, 4, 4);
imagesetpixel($dst, 0, 0, $white);
ob_start();
imagepng($dst);
$png = ob_get_clean();
imagedestroy($src);
imagedestroy($dst);
echo strlen($png) > 8 ? "ok\n" : "fail\n";
