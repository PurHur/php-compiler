<?php

foreach ([
    'imagecharup', 'imagestringup', 'imagegammacorrect',
    'imageinterlace', 'imagesetclip', 'imagegetclip',
    'imagechar', 'imagestring',
] as $f) {
    echo $f, '=', function_exists($f) ? '1' : '0', PHP_EOL;
}

$im = imagecreatetruecolor(40, 40);
imagealphablending($im, false);
$bg = imagecolorallocate($im, 0, 0, 0);
$fg = imagecolorallocate($im, 255, 255, 255);
imagefilledrectangle($im, 0, 0, 39, 39, $bg);
imagecharup($im, 5, 10, 30, 'A', $fg);
$ink = 0;
for ($y = 0; $y < 40; ++$y) {
    for ($x = 0; $x < 40; ++$x) {
        if ((imagecolorat($im, $x, $y) & 0xFFFFFF) === ($fg & 0xFFFFFF)) {
            ++$ink;
        }
    }
}
echo 'charup_ink=', (int) ($ink > 0), PHP_EOL;

imagestringup($im, 5, 25, 35, 'Hi', $fg);
echo 'stringup_ok=1', PHP_EOL;

$g = imagecreatetruecolor(2, 2);
imagealphablending($g, false);
$c = imagecolorallocate($g, 128, 64, 32);
imagefilledrectangle($g, 0, 0, 1, 1, $c);
imagegammacorrect($g, 1.0, 2.0);
$after = imagecolorat($g, 0, 0) & 0xFFFFFF;
echo 'gamma_changed=', (int) ($after !== ($c & 0xFFFFFF)), PHP_EOL;

$il = imagecreatetruecolor(4, 4);
echo 'interlace0=', (int) imageinterlace($il), PHP_EOL;
echo 'interlace1=', (int) imageinterlace($il, true), PHP_EOL;
echo 'interlace2=', (int) imageinterlace($il), PHP_EOL;

$cl = imagecreatetruecolor(20, 20);
imagealphablending($cl, false);
$bg = imagecolorallocate($cl, 0, 0, 0);
$fg = imagecolorallocate($cl, 255, 0, 0);
imagefilledrectangle($cl, 0, 0, 19, 19, $bg);
imagesetclip($cl, 5, 5, 10, 10);
$clip = imagegetclip($cl);
echo 'clip=', implode(',', $clip), PHP_EOL;
imagesetpixel($cl, 7, 7, $fg);
imagesetpixel($cl, 0, 0, $fg);
echo 'clip_in=', (int) ((imagecolorat($cl, 7, 7) & 0xFFFFFF) === ($fg & 0xFFFFFF)), PHP_EOL;
echo 'clip_out=', (int) ((imagecolorat($cl, 0, 0) & 0xFFFFFF) === ($bg & 0xFFFFFF)), PHP_EOL;
echo 'ok', PHP_EOL;
