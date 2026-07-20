--TEST--
tidy_*_count host soft path (#21541)
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
@tidy_diagnose($t);
echo 'err=', (int) tidy_error_count($t), "\n";
echo 'warn=', (int) tidy_warning_count($t), "\n";
echo 'acc=', (int) tidy_access_count($t), "\n";
echo 'cfg=', (int) tidy_config_count($t), "\n";
echo "ok\n";
?>
--EXPECTF--
tidy_live=%d
%a
