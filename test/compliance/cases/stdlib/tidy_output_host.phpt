--TEST--
tidy_clean_repair / tidy_get_output host soft path (#21499)
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
echo 'clean=', (int) tidy_clean_repair($t), "\n";
$out = tidy_get_output($t);
echo is_string($out) && strlen($out) > 0 ? 'out=Y' : 'out=N', "\n";
echo is_string($t->value) && strlen((string) $t->value) > 0 ? 'value=Y' : 'value=N', "\n";
echo "ok\n";
?>
--EXPECTF--
tidy_live=%d
%a
