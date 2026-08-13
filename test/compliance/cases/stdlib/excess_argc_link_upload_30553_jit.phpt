--TEST--
symlink/readlink/linkinfo/is_uploaded_file/move_uploaded_file excess argc → ArgumentCountError — JIT (#30553)
--ENV--
PHP_COMPILER_PROFILE=8.4
--JIT--
--FILE--
<?php
$cases = [
    'symlink("/a", "/b", "x")',
    'readlink("/a", "x")',
    'linkinfo("/a", "x")',
    'is_uploaded_file("/a", "x")',
    'move_uploaded_file("/a", "/b", "x")',
];
foreach ($cases as $code) {
    try {
        eval($code.';');
        echo "$code => NO_THROW\n";
    } catch (ArgumentCountError $e) {
        echo $e->getMessage(), "\n";
    } catch (Throwable $e) {
        echo get_class($e), ':', $e->getMessage(), "\n";
    }
}
?>
--EXPECT--
symlink() expects exactly 2 arguments, 3 given
readlink() expects exactly 1 argument, 2 given
linkinfo() expects exactly 1 argument, 2 given
is_uploaded_file() expects exactly 1 argument, 2 given
move_uploaded_file() expects exactly 2 arguments, 3 given
