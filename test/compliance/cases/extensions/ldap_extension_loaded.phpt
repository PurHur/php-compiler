--TEST--
ext ldap extension_loaded('ldap') false until full ext/ldap ships (#17680, ext/ldap/php_ldap.c)
--FILE--
<?php
declare(strict_types=1);

echo extension_loaded('ldap') ? "fail\n" : "ok\n";
?>
--EXPECT--
ok
