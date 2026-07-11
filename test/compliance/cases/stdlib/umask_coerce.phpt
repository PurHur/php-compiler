--TEST--
stdlib umask() — numeric-string mask coercion + array TypeError (#4545, ext/standard/filestat.c)
--FILE--
<?php
$saved = umask();
$prev = umask(0022);
$prev2 = umask("0022");
echo $prev2 === 18 ? "prev\n" : "bad\n";
echo umask() === 22 ? "str\n" : "bad\n";
umask($saved);
try {
    umask([]);
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
prev
str
umask(): Argument #1 ($mask) must be of type ?int, array given
