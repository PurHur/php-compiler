--TEST--
JIT: scandir('') — ValueError for empty directory path (#11031, ext/standard/dir.c)
--FILE--
<?php
try {
    scandir('');
    echo "ok\n";
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
scandir(): Argument #1 ($directory) must not be empty
