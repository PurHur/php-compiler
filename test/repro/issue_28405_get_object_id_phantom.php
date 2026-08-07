<?php

declare(strict_types=1);

/**
 * #28405 — get_object_id() is absent from php-src; spl_object_id remains.
 */

if (function_exists('get_object_id')) {
    echo "fail: get_object_id exists\n";
    exit(1);
}
if (!function_exists('spl_object_id')) {
    echo "fail: spl_object_id missing\n";
    exit(1);
}
$o = new stdClass();
if (!is_int(spl_object_id($o))) {
    echo "fail: spl_object_id not int\n";
    exit(1);
}

echo "ok\n";
