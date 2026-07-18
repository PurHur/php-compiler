--TEST--
stdlib gd imageloadfont/imagecolormatch (#20486, ext/gd/gd.c)
--FILE--
<?php
foreach (['imageloadfont', 'imagecolormatch'] as $n) {
    echo $n, '=', (int) function_exists($n), "\n";
}
$fontPath = __DIR__.'/test/fixtures/gd/gh13082.gdf';
if (!is_file($fontPath)) {
    $fontPath = dirname(__DIR__, 3).'/fixtures/gd/gh13082.gdf';
}
if (!is_file($fontPath)) {
    $fontPath = 'test/fixtures/gd/gh13082.gdf';
}
$font = imageloadfont($fontPath);
echo 'font=', ($font instanceof GdFont) ? '1' : '0', "\n";
$im = imagecreatetruecolor(32, 16);
$fg = imagecolorallocate($im, 255, 255, 255);
echo 'draw=', (int) imagestring($im, $font, 1, 1, 'A', $fg), "\n";

$ima = imagecreatetruecolor(4, 4);
$fill = imagecolorallocate($ima, 200, 100, 50);
imagefilledrectangle($ima, 0, 0, 3, 3, $fill);
$imb = imagecreate(4, 4);
imagecolorallocate($imb, 0, 0, 0);
echo 'match=', (int) imagecolormatch($ima, $imb), "\n";
$c = imagecolorsforindex($imb, 0);
echo 'r=', $c['red'], ' g=', $c['green'], ' b=', $c['blue'], "\n";

try {
    imagecolormatch(imagecreate(2, 2), imagecreate(2, 2));
} catch (ValueError $e) {
    echo 'err1=', (int) str_contains($e->getMessage(), 'must be TrueColor'), "\n";
}
$empty = imagecreate(2, 2);
try {
    imagecolormatch(imagecreatetruecolor(2, 2), $empty);
} catch (ValueError $e) {
    echo 'err2=', (int) str_contains($e->getMessage(), 'at least one color'), "\n";
}
echo 'ok', "\n";
?>
--EXPECT--
imageloadfont=1
imagecolormatch=1
font=1
draw=1
match=1
r=200 g=100 b=50
err1=1
err2=1
ok
