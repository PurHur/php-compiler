--TEST--
stdlib gd imageaffine identity + ValueError (#20404, ext/gd/gd.c)
--FILE--
<?php
echo 'exists=', (int) function_exists('imageaffine'), "\n";
$im = imagecreatetruecolor(4, 4);
$red = imagecolorallocate($im, 255, 0, 0);
imagefill($im, 0, 0, $red);
$out = imageaffine($im, [1, 0, 0, 1, 0, 0]);
echo 'type=', get_debug_type($out), "\n";
echo 'sx=', imagesx($out), ' sy=', imagesy($out), "\n";
echo 'color=', imagecolorat($out, 0, 0) & 0xFFFFFF, "\n";

$scaled = imageaffine($im, [2, 0, 0, 2, 0, 0]);
echo 'scale_type=', get_debug_type($scaled), "\n";
echo 'scale_sx=', imagesx($scaled), ' scale_sy=', imagesy($scaled), "\n";

try {
    imageaffine($im, [1, 0, 0]);
    echo "no_throw\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
}
?>
--EXPECT--
exists=1
type=GdImage
sx=4 sy=4
color=16711680
scale_type=GdImage
scale_sx=8 scale_sy=8
ValueError
