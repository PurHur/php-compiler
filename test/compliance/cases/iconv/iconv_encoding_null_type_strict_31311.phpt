--TEST--
iconv_get/set_encoding(null) TypeError under caller strict_types (#31311, ext/iconv/iconv.c)
--FILE--
<?php
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');
foreach ([
    'get' => static fn () => iconv_get_encoding(null),
    'set' => static fn () => iconv_set_encoding(null, 'UTF-8'),
] as $label => $factory) {
    try {
        $factory();
        echo "$label: uncaught\n";
    } catch (TypeError $e) {
        echo $label, ':', $e->getMessage(), "\n";
    }
}
echo var_export(is_array(iconv_get_encoding()), true), "\n";
?>
--EXPECT--
get:iconv_get_encoding(): Argument #1 ($type) must be of type string, null given
set:iconv_set_encoding(): Argument #1 ($type) must be of type string, null given
true
