--TEST--
iconv() null encodings TypeError under caller strict_types (#31309, ext/iconv/iconv.c)
--FILE--
<?php
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');
foreach ([
    'from' => static fn () => iconv(null, 'UTF-8', 'a'),
    'to' => static fn () => iconv('UTF-8', null, 'a'),
] as $label => $factory) {
    try {
        $factory();
        echo "$label: uncaught\n";
    } catch (TypeError $e) {
        echo $label, ':', $e->getMessage(), "\n";
    }
}
?>
--EXPECT--
from:iconv(): Argument #1 ($from_encoding) must be of type string, null given
to:iconv(): Argument #2 ($to_encoding) must be of type string, null given
