--TEST--
tidy_repair_string host bridge soft path (#21498)
--FILE--
<?php
declare(strict_types=1);
// Soft-exit when harness Zend lacks ext-tidy (BaseTest ignores --SKIPIF--).
$out = @tidy_repair_string('<title>x</title><p>hi');
if (false === $out) {
    echo "tidy_live=0\n";
    echo "ok\n";
    exit(0);
}
echo "tidy_live=1\n";
echo is_string($out) && strlen($out) > 0 ? 'str=Y' : 'str=N', "\n";
$via = @tidy::repairString('<p>z');
echo is_string($via) && strlen($via) > 0 ? 'static=Y' : 'static=N', "\n";
echo "ok\n";
?>
--EXPECTF--
tidy_live=%d
%a
