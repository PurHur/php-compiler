--TEST--
get_defined_constants(true) — ldap bucket when ext/ldap loaded (#18173, ExtensionConstantGroups.php)
--FILE--
<?php
declare(strict_types=1);

echo extension_loaded('ldap') ? "ok ext\n" : "fail ext\n";
$c = get_defined_constants(true);
echo isset($c['ldap']) ? "ok bucket\n" : "fail bucket\n";
--EXPECT--
ok ext
ok bucket
