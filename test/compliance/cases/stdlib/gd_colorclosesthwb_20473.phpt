--TEST--
stdlib gd imagecolorclosesthwb HWB nearest (#20473, ext/gd/gd.c)
--FILE--
<?php
echo 'exists=', (int) function_exists('imagecolorclosesthwb'), "\n";

$im = imagecreate(16, 16);
imagecolorallocate($im, 0, 0, 0);
$red = imagecolorallocate($im, 255, 0, 0);
imagecolorallocate($im, 0, 255, 0);
$idx = imagecolorclosesthwb($im, 200, 10, 10);
echo 'is_red=', (int) ($idx === $red), "\n";

$im2 = imagecreate(8, 8);
imagecolorallocate($im2, 0, 0, 0);
$r = imagecolorallocate($im2, 255, 0, 0);
$g = imagecolorallocate($im2, 0, 255, 0);
$b = imagecolorallocate($im2, 0, 0, 255);
imagecolorallocate($im2, 255, 255, 255);
echo 'near_r=', (int) (imagecolorclosesthwb($im2, 200, 10, 10) === $r), "\n";
echo 'near_g=', (int) (imagecolorclosesthwb($im2, 10, 200, 10) === $g), "\n";
echo 'near_b=', (int) (imagecolorclosesthwb($im2, 10, 10, 200) === $b), "\n";

$tc = imagecreatetruecolor(4, 4);
$pack = imagecolorclosesthwb($tc, 10, 20, 30);
echo 'tc_ok=', (int) ($pack === ((10 << 16) | (20 << 8) | 30)), "\n";

try {
    imagecolorclosesthwb($im, -1, 0, 0);
    echo "neg_ok\n";
} catch (ValueError $e) {
    echo "neg_ve\n";
}
?>
--EXPECT--
exists=1
is_red=1
near_r=1
near_g=1
near_b=1
tc_ok=1
neg_ve
