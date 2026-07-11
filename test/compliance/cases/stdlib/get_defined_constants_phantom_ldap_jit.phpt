--TEST--
get_defined_constants(true) — no phantom ldap bucket when ext/ldap unloaded JIT (#17800)
--JIT--
--FILE--
<?php
declare(strict_types=1);

echo extension_loaded('ldap') ? "fail ext\n" : "ok ext\n";
$c = get_defined_constants(true);
echo isset($c['ldap']) ? "fail bucket\n" : "ok bucket\n";
--EXPECT--
ok ext
ok bucket
