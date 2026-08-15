--TEST--
stdlib: file_get_contents/file_put_contents excess argc at-most wording JIT (#30677)
--FILE--
<?php
try {
    file_get_contents('/etc/hosts', false, null, 0, null, 1);
    echo "fgc excess NO_THROW\n";
} catch (Throwable $e) {
    echo 'fgc excess ', get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    file_get_contents();
    echo "fgc missing NO_THROW\n";
} catch (Throwable $e) {
    echo 'fgc missing ', get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    file_put_contents('/tmp/t', 'a', 0, null, 1);
    echo "fpc excess NO_THROW\n";
} catch (Throwable $e) {
    echo 'fpc excess ', get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    file_put_contents('/tmp/t');
    echo "fpc missing NO_THROW\n";
} catch (Throwable $e) {
    echo 'fpc missing ', get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
fgc excess ArgumentCountError: file_get_contents() expects at most 5 arguments, 6 given
fgc missing ArgumentCountError: file_get_contents() expects at least 1 argument, 0 given
fpc excess ArgumentCountError: file_put_contents() expects at most 4 arguments, 5 given
fpc missing ArgumentCountError: file_put_contents() expects at least 2 arguments, 1 given
