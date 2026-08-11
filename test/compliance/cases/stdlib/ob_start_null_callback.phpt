--TEST--
ob_start(null) accepts null callback like omitted (issue #30121, php-src output.c)
--FILE--
<?php
declare(strict_types=1);
error_reporting(E_ALL);
$r = ob_start(null);
echo 'ret=', var_export($r, true), ' level=', ob_get_level(), "\n";
echo 'body';
$buf = ob_get_clean();
echo 'buf=', $buf, ' after=', ob_get_level(), "\n";
--EXPECT--
buf=ret=true level=1
body after=0
