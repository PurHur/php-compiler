--TEST--
stdlib gd imagerectangle/imagedashedline (#20457, ext/gd/gd.c)
--FILE--
<?php
foreach (['imagerectangle', 'imagedashedline'] as $f) {
    echo $f, '=', (int) function_exists($f), "\n";
}

$im = imagecreatetruecolor(20, 20);
imagealphablending($im, false);
$bg = imagecolorallocate($im, 0, 0, 0);
$fg = imagecolorallocate($im, 255, 0, 0);
imagefilledrectangle($im, 0, 0, 19, 19, $bg);
imagerectangle($im, 2, 2, 17, 17, $fg);
echo 'rect_corner=', (int) ((imagecolorat($im, 2, 2) & 0xFFFFFF) === ($fg & 0xFFFFFF)), "\n";
echo 'rect_edge=', (int) ((imagecolorat($im, 10, 2) & 0xFFFFFF) === ($fg & 0xFFFFFF)), "\n";
echo 'rect_inside=', (int) ((imagecolorat($im, 10, 10) & 0xFFFFFF) === ($bg & 0xFFFFFF)), "\n";

$dash = imagecreatetruecolor(20, 20);
imagealphablending($dash, false);
$bg = imagecolorallocate($dash, 0, 0, 0);
$fg = imagecolorallocate($dash, 0, 255, 0);
imagefilledrectangle($dash, 0, 0, 19, 19, $bg);
imagedashedline($dash, 0, 0, 19, 19, $fg);
$ink = 0;
$gap = 0;
for ($i = 0; $i < 20; ++$i) {
    if ((imagecolorat($dash, $i, $i) & 0xFFFFFF) === ($fg & 0xFFFFFF)) {
        ++$ink;
    } else {
        ++$gap;
    }
}
echo 'dash_ink=', (int) ($ink > 0), "\n";
echo 'dash_gap=', (int) ($gap > 0), "\n";
echo 'ok', "\n";
?>
--EXPECT--
imagerectangle=1
imagedashedline=1
rect_corner=1
rect_edge=1
rect_inside=1
dash_ink=1
dash_gap=1
ok
