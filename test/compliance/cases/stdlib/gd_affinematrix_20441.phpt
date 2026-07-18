--TEST--
stdlib gd imageaffinematrixget/concat + IMG_AFFINE_* (#20441, ext/gd/gd.c)
--FILE--
<?php
foreach (['imageaffinematrixget', 'imageaffinematrixconcat'] as $fn) {
    echo $fn, '=', (int) function_exists($fn), "\n";
}
foreach ([
    'IMG_AFFINE_TRANSLATE',
    'IMG_AFFINE_SCALE',
    'IMG_AFFINE_ROTATE',
    'IMG_AFFINE_SHEAR_HORIZONTAL',
    'IMG_AFFINE_SHEAR_VERTICAL',
] as $c) {
    echo $c, '=', (int) defined($c), "\n";
}

$t = imageaffinematrixget(IMG_AFFINE_TRANSLATE, ['x' => 5, 'y' => 10]);
echo 'translate=', implode(',', $t), "\n";

$s = imageaffinematrixget(IMG_AFFINE_SCALE, ['x' => 2, 'y' => 3]);
echo 'scale=', implode(',', $s), "\n";

$c = imageaffinematrixconcat($t, $s);
echo 'concat=', implode(',', $c), "\n";

$r = imageaffinematrixget(IMG_AFFINE_ROTATE, 90);
echo 'rotate90_ok=', (int) ($r[1] > 0.999 && $r[1] < 1.001 && $r[2] > -1.001 && $r[2] < -0.999), "\n";
?>
--EXPECT--
imageaffinematrixget=1
imageaffinematrixconcat=1
IMG_AFFINE_TRANSLATE=1
IMG_AFFINE_SCALE=1
IMG_AFFINE_ROTATE=1
IMG_AFFINE_SHEAR_HORIZONTAL=1
IMG_AFFINE_SHEAR_VERTICAL=1
translate=1,0,0,1,5,10
scale=2,0,0,3,0,0
concat=2,0,0,3,10,30
rotate90_ok=1
