<?php

declare(strict_types=1);

$im = imagecreatetruecolor(10, 10);
$white = imagecolorallocate($im, 255, 255, 255);
imagefill($im, 0, 0, $white);
ob_start();
imagepng($im);
$png = ob_get_clean();
imagedestroy($im);
echo \strlen($png) > 8 ? "ok\n" : "fail\n";
