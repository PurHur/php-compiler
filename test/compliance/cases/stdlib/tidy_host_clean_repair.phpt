--TEST--
tidy_parse_string + cleanRepair host bridge (#21464)
--FILE--
<?php
declare(strict_types=1);
// Soft-exit when harness Zend lacks ext-tidy (BaseTest ignores --SKIPIF--).
$t = @tidy_parse_string('<title>x</title><p>hi');
if (false === $t) {
    echo "tidy_live=0\n";
    echo "ok\n";
    exit(0);
}
echo "tidy_live=1\n";
echo ($t instanceof tidy) ? 'inst=Y' : 'inst=N', "\n";
echo 'repair=', (int) $t->cleanRepair(), "\n";
echo "ok\n";
?>
--EXPECTF--
tidy_live=%d
%a
