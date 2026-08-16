--TEST--
gettimeofday/php_sapi_name/mb_list_encodings Reflection returns (#27906)
--FILE--
<?php
foreach (['gettimeofday', 'php_sapi_name', 'mb_list_encodings'] as $fn) {
    $r = new ReflectionFunction($fn);
    echo $fn, '=', $r->hasReturnType() ? (string) $r->getReturnType() : '-', PHP_EOL;
}
$tv = gettimeofday(as_float: true);
echo 'as_float=', is_float($tv) ? 'float' : get_debug_type($tv), PHP_EOL;
--EXPECT--
gettimeofday=array|float
php_sapi_name=string|false
mb_list_encodings=array
as_float=float
