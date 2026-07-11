--TEST--
ext ldap ldap_escape() — not advertised without ext/ldap (#17680)
--FILE--
<?php
declare(strict_types=1);

$phantom = function_exists('ldap_escape');
echo $phantom ? "fail\n" : "ok\n";
?>
--EXPECT--
ok
