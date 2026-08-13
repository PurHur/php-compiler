--TEST--
strip_tags() excess argc → ArgumentCountError (#30592)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
try {
    strip_tags('<a>b</a>', null, 'x');
    echo "NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo $e->getMessage(), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    strip_tags();
    echo "NO_THROW0\n";
} catch (ArgumentCountError $e) {
    echo $e->getMessage(), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
echo strip_tags('<b>ok</b>'), "\n";
echo strip_tags('<b>ok</b>', '<b>'), "\n";
?>
--EXPECT--
strip_tags() expects at most 2 arguments, 3 given
strip_tags() expects at least 1 argument, 0 given
ok
<b>ok</b>
