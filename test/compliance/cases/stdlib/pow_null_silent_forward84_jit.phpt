--TEST--
stdlib pow(null) silent coerce on 8.4 JIT — no float null DEP; fpow still DEP (#29322, re-#20951)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
error_reporting(E_ALL);
echo 'pow=', var_export(pow(null, 2), true), "\n";
echo 'pow2=', var_export(pow(2, null), true), "\n";
echo 'fpow=', var_export(fpow(null, 2.0), true), "\n";
echo 'ok=', var_export(pow(2, 3), true), "\n";
--EXPECTF--
PHP Deprecated:  fpow(): Passing null to parameter #1 ($num) of type float is deprecated in %s on line %d
pow=0
pow2=1
fpow=0.0
ok=8
