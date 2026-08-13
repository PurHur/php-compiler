--TEST--
stdlib JIT: timezone_abbreviations_list excess argc → ArgumentCountError (#30681)
--FILE--
<?php
try {
    timezone_abbreviations_list(1);
    echo "excess NO_THROW\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
$r = timezone_abbreviations_list();
echo 'ok_type=', gettype($r), "\n";
echo 'ok_nonempty=', (is_array($r) && count($r) > 0) ? '1' : '0', "\n";
--EXPECT--
ArgumentCountError: timezone_abbreviations_list() expects exactly 0 arguments, 1 given
ok_type=array
ok_nonempty=1
