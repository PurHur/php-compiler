--TEST--
ob_start() excess argc → ArgumentCountError (#30508)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
foreach ([
    static fn () => ob_start('trim', 0, 0, 'extra'),
    static fn () => ob_start('trim', 0, 0, 'extra', 'more'),
] as $fn) {
    try {
        $fn();
        echo "NO_THROW\n";
    } catch (ArgumentCountError $e) {
        echo $e->getMessage(), "\n";
    } catch (Throwable $e) {
        echo get_class($e), ':', $e->getMessage(), "\n";
    }
}
?>
--EXPECT--
ob_start() expects at most 3 arguments, 4 given
ob_start() expects at most 3 arguments, 5 given
