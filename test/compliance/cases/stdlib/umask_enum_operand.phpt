--TEST--
stdlib umask() — enum case mask operand TypeError uses ?int signature (#9628, ext/standard/filestat.c)
--FILE--
<?php
declare(strict_types=1);
enum E: int { case A = 0777; }
try {
    umask(E::A);
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
umask(): Argument #1 ($mask) must be of type ?int, E given
