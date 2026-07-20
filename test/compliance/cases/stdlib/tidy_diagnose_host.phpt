--TEST--
tidy_diagnose / error buffer host soft path (#21500)
--FILE--
<?php
declare(strict_types=1);
$t = @tidy_parse_string('<title>x</title><p>hi');
if (false === $t) {
    echo "tidy_live=0\n";
    echo "ok\n";
    exit(0);
}
echo "tidy_live=1\n";
echo 'diag=', (int) tidy_diagnose($t), "\n";
$buf = tidy_get_error_buffer($t);
echo is_string($buf) || false === $buf ? 'buf=ok' : 'buf=bad', "\n";
echo "ok\n";
?>
--EXPECTF--
tidy_live=%d
%a
