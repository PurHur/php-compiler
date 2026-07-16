<?php
declare(strict_types=1);

foreach (['imageline', 'imagestring', 'imagechar', 'imagefilledrectangle'] as $fn) {
    echo $fn, ': ', function_exists($fn) ? 'yes' : 'no', "\n";
}

$im = imagecreatetruecolor(100, 100);
$white = imagecolorallocate($im, 255, 255, 255);
$black = imagecolorallocate($im, 0, 0, 0);
imagefilledrectangle($im, 0, 0, 99, 99, $white);
imageline($im, 0, 0, 99, 99, $black);
imagestring($im, 3, 10, 10, 'OK', $black);
imagechar($im, 3, 10, 30, 'A', $black);
echo 'diag=', imagecolorat($im, 50, 50), "\n";
echo 'bg=', imagecolorat($im, 0, 1), "\n";
echo 'ok=', (imagecolorat($im, 50, 50) === $black) ? '1' : '0', "\n";
