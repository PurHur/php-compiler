--TEST--
iconv() JIT null encoding operands TypeError on 8.4 forward profile (#18993, ext/iconv/iconv.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
foreach ([
    'from' => static fn () => iconv(null, 'UTF-8', 'x'),
    'to' => static fn () => iconv('UTF-8', null, 'x'),
] as $label => $factory) {
    try {
        $factory();
        echo "$label: uncaught\n";
    } catch (TypeError $e) {
        echo $label.': '.$e->getMessage()."\n";
    }
}
?>
--EXPECT--
from: iconv(): Argument #1 ($from_encoding) must be of type string, null given
to: iconv(): Argument #2 ($to_encoding) must be of type string, null given
