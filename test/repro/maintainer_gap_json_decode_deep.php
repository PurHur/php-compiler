<?php

declare(strict_types=1);

/**
 * Maintainer repro: json_decode() default max depth 512 (#11637, ext/json/php_json.c).
 */

function nestJson(int $depth): string
{
    return $depth <= 0 ? '[]' : '['.nestJson($depth - 1).']';
}

$ok = json_decode(nestJson(510), true);
echo 'decode510=', var_export(null !== $ok, true), ' err=', json_last_error(), "\n";

$fail = json_decode(nestJson(511), true);
echo 'decode511=', var_export(null !== $fail, true), ' err=', json_last_error(), "\n";

if (null !== $ok && null === $fail && 1 === json_last_error()) {
    echo "json-depth-ok\n";
    exit(0);
}

exit(1);
