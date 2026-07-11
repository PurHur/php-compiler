<?php

declare(strict_types=1);

if (ini_get('enable_dl') !== '') {
    echo 'fail: ini_get(enable_dl) expected empty string, got '.var_export(ini_get('enable_dl'), true)."\n";
    exit(1);
}

if ('string' !== gettype(ini_get('enable_dl'))) {
    echo 'fail: gettype(ini_get(enable_dl)) expected string, got '.gettype(ini_get('enable_dl'))."\n";
    exit(1);
}

echo "ok\n";
