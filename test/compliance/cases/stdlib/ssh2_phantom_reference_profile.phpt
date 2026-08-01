--TEST--
stdlib ssh2 withheld on reference profile (#6385)
--FILE--
<?php
declare(strict_types=1);
echo function_exists('ssh2_connect') ? 'Y' : 'N', "\n";
echo extension_loaded('ssh2') ? 'Y' : 'N', "\n";
?>
--EXPECT--
N
N
