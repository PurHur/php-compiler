--TEST--
stdlib JIT sprintf/printf trailing % — ArgumentCountError/ValueError (#24661)
--JIT--
--FILE--
<?php
foreach ([
    fn () => sprintf('%'),
    fn () => sprintf('%', 1),
    fn () => printf('%'),
    fn () => sprintf('%s%', 'a', 1),
] as $i => $fn) {
    try {
        $fn();
        echo "ok{$i}\n";
    } catch (Throwable $e) {
        echo get_class($e), ':', $e->getMessage(), "\n";
    }
}
?>
--EXPECT--
ArgumentCountError:2 arguments are required, 1 given
ValueError:Missing format specifier at end of string
ArgumentCountError:2 arguments are required, 1 given
ValueError:Missing format specifier at end of string
