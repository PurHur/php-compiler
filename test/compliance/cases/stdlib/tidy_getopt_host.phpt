--TEST--
tidy_getopt / getConfig / getStatus host soft path (#21540)
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
$indent = tidy_getopt($t, 'indent');
echo is_bool($indent) || is_int($indent) || is_string($indent) ? 'opt=ok' : 'opt=bad', "\n";
$cfg = tidy_get_config($t);
echo is_array($cfg) ? 'cfg=ok' : 'cfg=bad', "\n";
echo 'status=', (int) tidy_get_status($t), "\n";
echo 'm_opt=', (is_bool($t->getOpt('indent')) || is_int($t->getOpt('indent')) || is_string($t->getOpt('indent'))) ? 'ok' : 'bad', "\n";
echo 'm_cfg=', is_array($t->getConfig()) ? 'ok' : 'bad', "\n";
echo 'm_status=', (int) $t->getStatus(), "\n";
echo "ok\n";
?>
--EXPECTF--
tidy_live=%d
%a
