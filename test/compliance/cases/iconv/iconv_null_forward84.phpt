--TEST--
iconv() null encoding/string TypeError on 8.4 forward profile (#19387, re-#18993/#18242, ext/iconv/iconv.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
foreach ([
    'from' => static fn () => iconv(null, 'UTF-8', 'x'),
    'to' => static fn () => iconv('UTF-8', null, 'x'),
    'string' => static fn () => iconv('UTF-8', 'UTF-8', null),
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
string: iconv(): Argument #3 ($string) must be of type string, null given
