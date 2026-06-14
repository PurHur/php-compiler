--TEST--
stdlib fwrite() — enum case data operand TypeError (#5945, ext/standard/streamsfuncs.c, php-src-strict)
--FILE--
<?php
enum Ed: string { case D = 'data'; }
$f = fopen('php://memory', 'r+');
try {
    fwrite($f, Ed::D);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
fwrite(): Argument #2 ($data) must be of type string, Ed given
