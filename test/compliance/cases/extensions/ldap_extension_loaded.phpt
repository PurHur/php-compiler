--TEST--
ext ldap extension_loaded('ldap') true when ldap_escape ships (#18173, ext/ldap/php_ldap.c)
--FILE--
<?php
declare(strict_types=1);

echo extension_loaded('ldap') ? "ok\n" : "fail\n";
?>
--EXPECT--
ok
