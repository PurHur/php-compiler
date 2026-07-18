--TEST--
stdlib gd imageopenpolygon/imagepolygon stroke (#20431, ext/gd/gd.c)
--FILE--
<?php
foreach (['imageopenpolygon', 'imagepolygon'] as $f) {
    echo $f, '=', (int) function_exists($f), "\n";
}
echo 'imageclosepolygon_absent=', (int) !function_exists('imageclosepolygon'), "\n";

$open = imagecreatetruecolor(20, 20);
imagealphablending($open, false);
$bg = imagecolorallocate($open, 0, 0, 0);
$fg = imagecolorallocate($open, 255, 255, 255);
imagefilledrectangle($open, 0, 0, 19, 19, $bg);
imageopenpolygon($open, [2, 2, 16, 2, 2, 16], $fg);
echo 'open_no_close=', (int) (0 === (imagecolorat($open, 2, 9) & 0xFFFFFF)), "\n";
echo 'open_has_side=', (int) (0 !== (imagecolorat($open, 9, 2) & 0xFFFFFF)), "\n";

$closed = imagecreatetruecolor(20, 20);
imagealphablending($closed, false);
$bg = imagecolorallocate($closed, 0, 0, 0);
$fg = imagecolorallocate($closed, 255, 255, 255);
imagefilledrectangle($closed, 0, 0, 19, 19, $bg);
imagepolygon($closed, [2, 2, 16, 2, 2, 16], $fg);
echo 'closed_has_close=', (int) (0 !== (imagecolorat($closed, 2, 9) & 0xFFFFFF)), "\n";
echo 'closed_has_side=', (int) (0 !== (imagecolorat($closed, 9, 2) & 0xFFFFFF)), "\n";

try {
    imageopenpolygon($open, [1, 2], $fg);
    echo "odd_ok\n";
} catch (ValueError $e) {
    echo "odd_ve\n";
}
?>
--EXPECT--
imageopenpolygon=1
imagepolygon=1
imageclosepolygon_absent=1
open_no_close=1
open_has_side=1
closed_has_close=1
closed_has_side=1
odd_ve
