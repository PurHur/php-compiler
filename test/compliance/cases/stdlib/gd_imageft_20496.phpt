--TEST--
stdlib gd imageftbbox/imagefttext FreeType primaries (#20496, ext/gd/gd.c)
--FILE--
<?php
declare(strict_types=1);

foreach (['imageftbbox', 'imagefttext', 'imagettfbbox', 'imagettftext'] as $f) {
    echo $f, '=', (int) function_exists($f), "\n";
}

$font = '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf';
$bbox = imageftbbox(12.0, 0.0, $font, 'Hi');
echo is_array($bbox) && 8 === count($bbox) ? "ft_bbox_ok\n" : "ft_bbox_bad\n";
$bbox2 = imagettfbbox(12.0, 0.0, $font, 'Hi');
echo is_array($bbox2) && $bbox === $bbox2 ? "alias_bbox_match\n" : "alias_bbox_diff\n";

$im = imagecreatetruecolor(80, 40);
$white = imagecolorallocate($im, 255, 255, 255);
$black = imagecolorallocate($im, 0, 0, 0);
imagefilledrectangle($im, 0, 0, 79, 39, $white);
$drawn = imagefttext($im, 12.0, 0.0, 5, 25, $black, $font, 'Hi');
echo is_array($drawn) && 8 === count($drawn) ? "ft_draw_ok\n" : "ft_draw_bad\n";
?>
--EXPECT--
imageftbbox=1
imagefttext=1
imagettfbbox=1
imagettftext=1
ft_bbox_ok
alias_bbox_match
ft_draw_ok
