<?php
/** Repro #27906 — Reflection returns for gettimeofday / php_sapi_name / mb_list_encodings. */
foreach (['gettimeofday', 'php_sapi_name', 'mb_list_encodings'] as $fn) {
    $r = new ReflectionFunction($fn);
    echo $fn, '=', $r->hasReturnType() ? (string) $r->getReturnType() : '-', PHP_EOL;
}
// Named as_float must keep working (done-when).
$tv = gettimeofday(as_float: true);
echo 'as_float=', is_float($tv) ? 'float' : get_debug_type($tv), PHP_EOL;
echo 'sapi=', is_string(php_sapi_name()) ? 'string' : 'bad', PHP_EOL;
echo 'mb=', is_array(mb_list_encodings()) ? 'array' : 'bad', PHP_EOL;
