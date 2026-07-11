<?php
declare(strict_types=1);

$r = filter_var('abc', FILTER_VALIDATE_REGEXP, ['options' => ['regexp' => '/^a/']]);
if ('abc' !== $r) {
    echo 'fail: regexp expected abc, got ';
    var_export($r);
    exit(1);
}

$r2 = filter_var('x', FILTER_VALIDATE_INT, ['flags' => FILTER_NULL_ON_FAILURE]);
if (null !== $r2) {
    echo 'fail: int null on failure expected null, got ';
    var_export($r2);
    exit(1);
}

echo "ok\n";
