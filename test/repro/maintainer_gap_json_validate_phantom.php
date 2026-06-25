<?php

declare(strict_types=1);

// Issue #11826 — json_validate() is PHP 8.3+; Zend 8.2 reference must not advertise it.
echo 'fn='.(function_exists('json_validate') ? 'yes' : 'no')."\n";

if (function_exists('json_validate')) {
    echo "fail: json_validate advertised on reference profile\n";
    exit(1);
}

echo "ok\n";
