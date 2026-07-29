--TEST--
AOT stream_get_contents() length < -1 throws ValueError (#24560)
--FILE--
<?php
declare(strict_types=1);
$f = fopen('php://memory', 'r+');
fwrite($f, 'abcd');
rewind($f);
try {
    stream_get_contents($f, -2);
    echo "uncaught\n";
} catch (ValueError $e) {
    echo "ValueError\n";
    echo $e->getMessage(), "\n";
}
--EXPECT--
ValueError
stream_get_contents(): Argument #2 ($length) must be greater than or equal to -1
