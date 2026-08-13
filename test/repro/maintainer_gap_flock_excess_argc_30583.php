<?php
$f = fopen('php://memory', 'r+');
$b = false;
try {
    $r = flock($f, LOCK_SH, $b, 'extra');
    echo 'NO_THROW r=';
    var_export($r);
    echo "\n";
} catch (ArgumentCountError $e) {
    echo $e->getMessage(), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
// Legal 2- and 3-arg still work.
$b2 = -1;
$ok2 = flock($f, LOCK_UN);
$ok3 = flock($f, LOCK_SH, $b2);
echo is_bool($ok2) ? '2ok' : '2fail', "\n";
echo is_bool($ok3) ? '3ok' : '3fail', ' wb=', (int) $b2, "\n";
fclose($f);
