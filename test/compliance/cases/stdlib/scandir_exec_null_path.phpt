--TEST--
stdlib scandir()/exec() null path — ValueError not TypeError (#18371, ext/standard/dir.c, exec.c)
--FILE--
<?php
try {
    scandir(null);
    echo "scandir_no_exception\n";
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}
try {
    exec(null);
    echo "exec_no_exception\n";
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
scandir(): Argument #1 ($directory) must not be empty
exec(): Argument #1 ($command) must not be empty
