<?php
declare(strict_types=1);

/**
 * Repro for #20441 — imageaffinematrixget()/concat() + IMG_AFFINE_*.
 */
foreach (['imageaffinematrixget', 'imageaffinematrixconcat', 'imageaffine'] as $f) {
    echo $f, '=', function_exists($f) ? 'yes' : 'no', PHP_EOL;
}
foreach ([
    'IMG_AFFINE_TRANSLATE',
    'IMG_AFFINE_SCALE',
    'IMG_AFFINE_ROTATE',
    'IMG_AFFINE_SHEAR_HORIZONTAL',
    'IMG_AFFINE_SHEAR_VERTICAL',
] as $c) {
    echo $c, '=', defined($c) ? 'yes' : 'no', PHP_EOL;
}

$t = imageaffinematrixget(IMG_AFFINE_TRANSLATE, ['x' => 5, 'y' => 10]);
echo 'translate=', implode(',', array_map(static fn ($v) => (string) (0.0 + $v), $t)), PHP_EOL;

$s = imageaffinematrixget(IMG_AFFINE_SCALE, ['x' => 2, 'y' => 3]);
echo 'scale=', implode(',', array_map(static fn ($v) => (string) (0.0 + $v), $s)), PHP_EOL;

$c = imageaffinematrixconcat($t, $s);
echo 'concat=', implode(',', array_map(static fn ($v) => (string) (0.0 + $v), $c)), PHP_EOL;

$r = imageaffinematrixget(IMG_AFFINE_ROTATE, 90);
echo 'rotate90_sin=', ($r[1] > 0.999 && $r[1] < 1.001) ? 'yes' : 'no', PHP_EOL;
echo 'rotate90_neg_cos_ish=', ($r[2] > -1.001 && $r[2] < -0.999) ? 'yes' : 'no', PHP_EOL;
