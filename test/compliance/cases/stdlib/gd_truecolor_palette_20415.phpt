--TEST--
stdlib gd imageistruecolor/imagetruecolortopalette/imagepalettetotruecolor (#20415, ext/gd/gd.c)
--FILE--
<?php
foreach (['imageistruecolor', 'imagetruecolortopalette', 'imagepalettetotruecolor', 'imagecreate'] as $fn) {
    echo $fn, '=', (int) function_exists($fn), "\n";
}

$tc = imagecreatetruecolor(8, 8);
echo 'tc_istrue=', (int) imageistruecolor($tc), "\n";
$red = imagecolorallocate($tc, 255, 0, 0);
imagefill($tc, 0, 0, $red);
echo 't2p=', (int) imagetruecolortopalette($tc, false, 16), "\n";
echo 'after_t2p_istrue=', (int) imageistruecolor($tc), "\n";
echo 'p2t=', (int) imagepalettetotruecolor($tc), "\n";
echo 'after_p2t_istrue=', (int) imageistruecolor($tc), "\n";
echo 'roundtrip_color=', imagecolorat($tc, 0, 0) & 0xFFFFFF, "\n";

$pal = imagecreate(8, 8);
echo 'pal_istrue=', (int) imageistruecolor($pal), "\n";
$c = imagecolorallocate($pal, 0, 255, 0);
imagefill($pal, 0, 0, $c);
echo 'pal_colorat=', imagecolorat($pal, 0, 0), "\n";
echo 'pal_to_tc=', (int) imagepalettetotruecolor($pal), "\n";
echo 'pal_after_istrue=', (int) imageistruecolor($pal), "\n";
echo 'pal_expanded=', imagecolorat($pal, 0, 0) & 0xFFFFFF, "\n";

$noop = imagecreatetruecolor(2, 2);
echo 'p2t_on_tc=', (int) imagepalettetotruecolor($noop), "\n";
$noopPal = imagecreate(2, 2);
echo 't2p_on_pal=', (int) imagetruecolortopalette($noopPal, false, 8), "\n";

try {
    imagetruecolortopalette(imagecreatetruecolor(2, 2), false, 0);
    echo "no_throw\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
}
?>
--EXPECT--
imageistruecolor=1
imagetruecolortopalette=1
imagepalettetotruecolor=1
imagecreate=1
tc_istrue=1
t2p=1
after_t2p_istrue=0
p2t=1
after_p2t_istrue=1
roundtrip_color=16711680
pal_istrue=0
pal_colorat=0
pal_to_tc=1
pal_after_istrue=1
pal_expanded=65280
p2t_on_tc=1
t2p_on_pal=1
ValueError
