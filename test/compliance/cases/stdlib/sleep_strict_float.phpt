--TEST--
stdlib sleep()/usleep() strict_types float — TypeError (#10073, ext/standard/basic_functions.c)
--FILE--
<?php
declare(strict_types=1);
try {
    sleep(1.5);
} catch (Throwable $e) {
    echo 'sleep: ', get_class($e), "\n";
}
try {
    usleep(1.5);
} catch (Throwable $e) {
    echo 'usleep: ', get_class($e), "\n";
}
?>
--EXPECT--
sleep: TypeError
usleep: TypeError
