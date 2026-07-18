--TEST--
stdlib gd imageellipse/imagefilledellipse (#20438, ext/gd/gd.c)
--FILE--
<?php
foreach (['imageellipse', 'imagefilledellipse', 'imagecreatetruecolor'] as $f) {
    echo $f, '=', (int) function_exists($f), "\n";
}

$im = imagecreatetruecolor(40, 40);
imagealphablending($im, false);
$bg = imagecolorallocate($im, 0, 0, 0);
$fg = imagecolorallocate($im, 255, 255, 255);
imagefilledrectangle($im, 0, 0, 39, 39, $bg);
imageellipse($im, 20, 20, 30, 20, $fg);
echo 'stroke_right=', (int) ((imagecolorat($im, 35, 20) & 0xFFFFFF) !== 0), "\n";
echo 'stroke_center_clear=', (int) ((imagecolorat($im, 20, 20) & 0xFFFFFF) === 0), "\n";
$strokeInk = 0;
for ($y = 0; $y < 40; ++$y) {
    for ($x = 0; $x < 40; ++$x) {
        if ((imagecolorat($im, $x, $y) & 0xFFFFFF) !== 0) {
            ++$strokeInk;
        }
    }
}
echo 'stroke_ink=', (int) ($strokeInk > 0), "\n";

$fill = imagecreatetruecolor(40, 40);
imagealphablending($fill, false);
imagefilledrectangle($fill, 0, 0, 39, 39, $bg);
imagefilledellipse($fill, 20, 20, 30, 20, $fg);
echo 'fill_center=', (int) ((imagecolorat($fill, 20, 20) & 0xFFFFFF) !== 0), "\n";
echo 'fill_edge=', (int) ((imagecolorat($fill, 35, 20) & 0xFFFFFF) !== 0), "\n";

try {
    imagefilledellipse($fill, 20, 20, -1, 10, $fg);
    echo "neg_ok\n";
} catch (ValueError $e) {
    echo "neg_ve\n";
}
?>
--EXPECT--
imageellipse=1
imagefilledellipse=1
imagecreatetruecolor=1
stroke_right=1
stroke_center_clear=1
stroke_ink=1
fill_center=1
fill_edge=1
neg_ve
