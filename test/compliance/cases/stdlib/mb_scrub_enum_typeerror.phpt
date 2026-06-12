--TEST--
stdlib mb_scrub() — backed enum case TypeError (#6050, php-src-strict)
--FILE--
<?php
enum E: string { case A = 'x'; }
try {
    mb_scrub(E::A, 'UTF-8');
    echo "no throw\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
mb_scrub(): Argument #1 ($string) must be of type string, E given
