--TEST--
stdlib gd imagefilledpolygon scanline fill (#20448, ext/gd/gd.c)
--FILE--
<?php
echo 'imagefilledpolygon=', (int) function_exists('imagefilledpolygon'), "\n";

$im = imagecreatetruecolor(20, 20);
imagealphablending($im, false);
$bg = imagecolorallocate($im, 0, 0, 0);
$fg = imagecolorallocate($im, 255, 255, 255);
imagefilledrectangle($im, 0, 0, 19, 19, $bg);
imagefilledpolygon($im, [2, 2, 16, 2, 2, 16], $fg);
echo 'interior=', (int) (0 !== (imagecolorat($im, 5, 4) & 0xFFFFFF)), "\n";
echo 'outside=', (int) (0 === (imagecolorat($im, 15, 15) & 0xFFFFFF)), "\n";
echo 'edge_top=', (int) (0 !== (imagecolorat($im, 9, 2) & 0xFFFFFF)), "\n";
echo 'edge_diag=', (int) (0 !== (imagecolorat($im, 9, 9) & 0xFFFFFF)), "\n";

try {
    imagefilledpolygon($im, [1, 2], $fg);
    echo "odd_ok\n";
} catch (ValueError $e) {
    echo "odd_ve\n";
}

try {
    imagefilledpolygon($im, [1, 2, 3, 4], $fg);
    echo "lt3_ok\n";
} catch (ValueError $e) {
    echo "lt3_ve\n";
}
?>
--EXPECT--
imagefilledpolygon=1
interior=1
outside=1
edge_top=1
edge_diag=1
odd_ve
lt3_ve
