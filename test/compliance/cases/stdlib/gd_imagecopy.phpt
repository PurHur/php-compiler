--TEST--
stdlib gd imagecopy/imagesetpixel blit + PNG round-trip (issue #6292)
--FILE--
<?php
declare(strict_types=1);

$src = imagecreatetruecolor(4, 4);
$dst = imagecreatetruecolor(8, 8);
$white = imagecolorallocate($src, 255, 255, 255);
imagefill($src, 0, 0, $white);
echo function_exists('imagecopy') ? "1" : "0";
echo function_exists('imagecopymerge') ? "1" : "0";
echo function_exists('imagecopyresampled') ? "1" : "0";
echo "\n";
imagecopy($dst, $src, 2, 2, 0, 0, 4, 4);
imagesetpixel($dst, 0, 0, $white);
ob_start();
imagepng($dst);
$png = ob_get_clean();
imagedestroy($src);
imagedestroy($dst);
echo strlen($png) > 8 ? "ok\n" : "fail\n";
?>
--EXPECT--
111
ok
