--TEST--
stdlib ldap withheld on reference profile — extension_loaded false (#18211, ext/ldap/php_ldap.c)
--FILE--
<?php
declare(strict_types=1);

$phantom = extension_loaded('ldap')
    || function_exists('ldap_escape')
    || \in_array('ldap', get_loaded_extensions(), true);
echo $phantom ? "fail\n" : "ok\n";
--EXPECT--
ok
