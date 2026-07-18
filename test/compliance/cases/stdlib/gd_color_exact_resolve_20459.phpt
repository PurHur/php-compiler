--TEST--
stdlib gd colortransparent/exact/resolve (+alpha) (#20459, ext/gd/gd.c)
--FILE--
<?php
$fns = [
    'imagecolortransparent',
    'imagecolorexact',
    'imagecolorresolve',
    'imagecolorexactalpha',
    'imagecolorresolvealpha',
    'imagecolorclosestalpha',
];
foreach ($fns as $f) {
    echo $f, '=', (int) function_exists($f), "\n";
}

$im = imagecreate(8, 8);
$r = imagecolorallocate($im, 255, 0, 0);
$g = imagecolorallocate($im, 0, 255, 0);
echo 'exact_r=', (int) (imagecolorexact($im, 255, 0, 0) === $r), "\n";
echo 'exact_miss=', imagecolorexact($im, 1, 2, 3), "\n";
$resolved = imagecolorresolve($im, 0, 0, 255);
echo 'resolve_new=', (int) ($resolved >= 0), "\n";
echo 'exact_after=', (int) (imagecolorexact($im, 0, 0, 255) === $resolved), "\n";

echo 'trans_default=', imagecolortransparent($im), "\n";
echo 'trans_set=', imagecolortransparent($im, $r), "\n";
echo 'trans_get=', imagecolortransparent($im), "\n";

$tc = imagecreatetruecolor(4, 4);
$pack = imagecolorclosestalpha($tc, 10, 20, 30, 5);
$comps = imagecolorsforindex($tc, $pack);
echo 'closestalpha_a=', $comps['alpha'], "\n";
echo 'exactalpha_tc=', (int) (imagecolorexactalpha($tc, 10, 20, 30, 5) === $pack), "\n";
?>
--EXPECT--
imagecolortransparent=1
imagecolorexact=1
imagecolorresolve=1
imagecolorexactalpha=1
imagecolorresolvealpha=1
imagecolorclosestalpha=1
exact_r=1
exact_miss=-1
resolve_new=1
exact_after=1
trans_default=-1
trans_set=0
trans_get=0
closestalpha_a=5
exactalpha_tc=1
