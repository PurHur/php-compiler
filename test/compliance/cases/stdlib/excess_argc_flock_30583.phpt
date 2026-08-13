--TEST--
flock() excess argc → ArgumentCountError (#30583)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$f = fopen('php://memory', 'r+');
$b = false;
try {
    flock($f, LOCK_SH, $b, 'extra');
    echo "NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo $e->getMessage(), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
$b2 = -1;
$ok2 = flock($f, LOCK_UN);
$ok3 = flock($f, LOCK_SH, $b2);
echo is_bool($ok2) ? '2ok' : '2fail', "\n";
echo is_bool($ok3) ? '3ok' : '3fail', "\n";
fclose($f);
?>
--EXPECT--
flock() expects at most 3 arguments, 4 given
2ok
3ok
