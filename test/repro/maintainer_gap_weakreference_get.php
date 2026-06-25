<?php

declare(strict_types=1);

$ref = WeakReference::create(new stdClass());
$obj = $ref->get();
echo 'is_null=', var_export($obj, true), "\n";
echo 'type=', get_debug_type($obj), "\n";

if (null !== $obj) {
    echo "FAIL expected NULL from WeakReference::get()\n";
    exit(1);
}
echo "ok\n";
