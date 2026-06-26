<?php
declare(strict_types=1);

$r = filter_var('x', FILTER_VALIDATE_INT, ['flags' => FILTER_NULL_ON_FAILURE]);
if (null !== $r) {
    echo 'fail: expected null, got ';
    var_export($r);
    exit(1);
}

echo "ok\n";
