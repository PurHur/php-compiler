--TEST--
get_defined_constants(true) — ldap bucket when ext/ldap loaded JIT (#18173)
--JIT--
--FILE--
<?php
declare(strict_types=1);

echo extension_loaded('ldap') ? "ok ext\n" : "fail ext\n";
$c = get_defined_constants(true);
echo isset($c['ldap']) ? "ok bucket\n" : "fail bucket\n";
--EXPECT--
ok ext
ok bucket
