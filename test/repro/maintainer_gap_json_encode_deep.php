<?php

declare(strict_types=1);

/**
 * Maintainer repro: json_encode() default max depth 512 (#11637, ext/json/php_json.c).
 */

function nest(int $depth): array
{
    return $depth <= 0 ? [] : [nest($depth - 1)];
}

$fail = json_encode(nest(512));
echo 'encode512=', var_export($fail !== false, true), ' err=', json_last_error(), "\n";

if (false === $fail && 1 === json_last_error()) {
    echo "json-depth-ok\n";
    exit(0);
}

exit(1);
