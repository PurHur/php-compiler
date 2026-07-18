--TEST--
stdlib gd imagecolorsforindex/imagecolorclosest/imagecolorset (#20440, ext/gd/gd.c)
--FILE--
<?php
foreach (['imagecolorsforindex', 'imagecolorclosest', 'imagecolorset', 'imagecolorallocate'] as $f) {
    echo $f, '=', (int) function_exists($f), "\n";
}

$im = imagecreate(8, 8);
$red = imagecolorallocate($im, 255, 0, 0);
$near = imagecolorallocate($im, 10, 200, 10);
$comps = imagecolorsforindex($im, $red);
echo 'forindex_r=', $comps['red'], "\n";
echo 'forindex_g=', $comps['green'], "\n";
echo 'forindex_b=', $comps['blue'], "\n";
echo 'forindex_a=', $comps['alpha'], "\n";

$closest = imagecolorclosest($im, 12, 190, 8);
echo 'closest=', (int) ($closest === $near), "\n";

$set = imagecolorset($im, $red, 0, 0, 255);
echo 'set_null=', (int) (null === $set), "\n";
$after = imagecolorsforindex($im, $red);
echo 'after_b=', $after['blue'], "\n";
echo 'set_bad=', (int) (false === imagecolorset($im, 99, 1, 2, 3)), "\n";

try {
    imagecolorsforindex($im, 99);
    echo "oor_ok\n";
} catch (ValueError $e) {
    echo "oor_ve\n";
}
?>
--EXPECT--
imagecolorsforindex=1
imagecolorclosest=1
imagecolorset=1
imagecolorallocate=1
forindex_r=255
forindex_g=0
forindex_b=0
forindex_a=0
closest=1
set_null=1
after_b=255
set_bad=1
oor_ve
