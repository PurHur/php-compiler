--TEST--
stdlib dechex()/decbin()/decoct() — backed enum case TypeError (#6018, ext/standard/math.c, php-src-strict)
--FILE--
<?php
enum E: int { case A = 10; }
$case = E::A;
foreach (['dechex', 'decbin', 'decoct'] as $fn) {
    try {
        $fn($case);
        echo "$fn: uncaught\n";
    } catch (TypeError $e) {
        echo $e->getMessage(), "\n";
    }
}
--EXPECT--
dechex(): Argument #1 ($num) must be of type int, E given
decbin(): Argument #1 ($num) must be of type int, E given
decoct(): Argument #1 ($num) must be of type int, E given
