--TEST--
stdlib gd imageresolution get/set DPI (#20430, ext/gd/gd.c)
--FILE--
<?php
echo 'imageresolution=', (int) function_exists('imageresolution'), "\n";
$im = imagecreatetruecolor(8, 8);
$def = imageresolution($im);
echo 'default=', $def[0], ',', $def[1], "\n";
echo 'set=', (int) imageresolution($im, 300, 300), "\n";
$after = imageresolution($im);
echo 'after=', $after[0], ',', $after[1], "\n";
echo 'set_one=', (int) imageresolution($im, 150), "\n";
$one = imageresolution($im);
echo 'after_one=', $one[0], ',', $one[1], "\n";
echo 'set_y=', (int) imageresolution($im, null, 72), "\n";
$y = imageresolution($im);
echo 'after_y=', $y[0], ',', $y[1], "\n";
try {
    imageresolution($im, -1);
    echo "neg_ok\n";
} catch (ValueError $e) {
    echo "neg_ve\n";
}
?>
--EXPECT--
imageresolution=1
default=96,96
set=1
after=300,300
set_one=1
after_one=150,150
set_y=1
after_y=72,72
neg_ve
