--TEST--
stdlib stream_get_contents() — enum case length operand TypeError (#6008, ext/standard/streamsfuncs.c)
--FILE--
<?php
enum E: string { case A = 'x'; }
$f = tmpfile();
try {
    stream_get_contents($f, E::A);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
fclose($f);
--EXPECT--
stream_get_contents(): Argument #2 ($length) must be of type ?int, E given
