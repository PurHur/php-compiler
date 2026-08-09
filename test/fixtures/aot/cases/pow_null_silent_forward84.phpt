--TEST--
AOT: pow(null) silent coerce on 8.4 — no float null DEP (#29322, re-#20951)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
// DEP on stderr (AotTest ignores). Only null operands — int**int AOT path SIGSEGVs under
// HELPER_RUNTIME_O=0 (pre-existing; not this issue). Operator-path null coerce is the claim.
error_reporting(0);
echo var_export(pow(null, 2), true), "\n";
echo var_export(pow(2, null), true), "\n";
echo var_export(pow(null, null), true), "\n";
--EXPECT--
0
1
1
