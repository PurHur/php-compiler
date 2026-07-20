--TEST--
tidy_parse_file host soft path (#21501)
--FILE--
<?php
declare(strict_types=1);
$probe = @tidy_parse_string('<p>x');
if (false === $probe) {
    echo "tidy_live=0\n";
    echo "ok\n";
    exit(0);
}
echo "tidy_live=1\n";
echo 'into=', (int) $probe->parseString('<p>y'), "\n";
$f = @tidy_parse_file(__FILE__);
echo false === $f ? 'file=false' : 'file=obj', "\n";
echo "ok\n";
?>
--EXPECTF--
tidy_live=%d
%a
