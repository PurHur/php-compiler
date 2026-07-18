<?php

declare(strict_types=1);

$checks = [
    'imagefilter',
    'imageflip',
    'imagecrop',
    'imagecropauto',
    'imagerotate',
    'imagescale',
    'imageconvolution',
    'imagecopyresized',
];

foreach ($checks as $fn) {
    echo $fn, ': ', function_exists($fn) ? 'yes' : 'no', "\n";
}
