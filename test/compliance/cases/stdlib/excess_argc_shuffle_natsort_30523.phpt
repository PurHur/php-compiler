--TEST--
shuffle/natsort/natcasesort excess argc → ArgumentCountError (#30523)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
foreach ([
    static function () {
        $a = [1];
        shuffle($a, 'x');
    },
    static function () {
        $a = ['a'];
        natsort($a, 'x');
    },
    static function () {
        $a = ['a'];
        natcasesort($a, 'x');
    },
    static function () {
        shuffle();
    },
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
shuffle() expects exactly 1 argument, 2 given
natsort() expects exactly 1 argument, 2 given
natcasesort() expects exactly 1 argument, 2 given
shuffle() expects exactly 1 argument, 0 given
