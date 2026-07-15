<?php

declare(strict_types=1);

var_export(function_exists('imagesx'));
echo "\n";
$im = imagecreatetruecolor(3, 2);
echo imagesx($im), 'x', imagesy($im), "\n";
$white = imagecolorallocate($im, 255, 255, 255);
imagefill($im, 0, 0, $white);
var_export(imagecolorat($im, 0, 0));
echo "\n";
