<?php
declare(strict_types=1);

$r1 = filter_var('not-int', FILTER_VALIDATE_INT, ['flags' => FILTER_NULL_ON_FAILURE]);
if (null !== $r1) {
    echo 'fail: int expected null, got ';
    var_export($r1);
    exit(1);
}

$r2 = filter_var('bad@', FILTER_VALIDATE_EMAIL, ['flags' => FILTER_NULL_ON_FAILURE]);
if (null !== $r2) {
    echo 'fail: email expected null, got ';
    var_export($r2);
    exit(1);
}

echo "ok\n";
