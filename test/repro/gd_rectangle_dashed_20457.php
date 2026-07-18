<?php
declare(strict_types=1);

/**
 * Repro for #20457 — imagerectangle + imagedashedline registration and draw.
 */
$im = imagecreatetruecolor(20, 20);
$c = imagecolorallocate($im, 255, 0, 0);
imagerectangle($im, 2, 2, 17, 17, $c);
imagedashedline($im, 0, 0, 19, 19, $c);
echo "ok\n";
