--TEST--
posix_getpgrp() matches posix_getpgid(0) (issue #19475)
--FILE--
<?php
declare(strict_types=1);

echo function_exists('posix_getpgrp') ? 'yes' : 'no', "\n";
$a = posix_getpgrp();
$b = posix_getpgid(0);
var_export(is_int($a) && $a > 0);
echo "\n";
var_export($a === $b);
echo "\n";
--EXPECT--
yes
true
true
