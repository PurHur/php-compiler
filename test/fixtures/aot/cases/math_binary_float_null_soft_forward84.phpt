--TEST--
AOT: fdiv/fmod/hypot/atan2(null) soft-null coerce on 8.4 (#29319, re-#24198)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
// DEP on stderr (AotTest ignores); assert coerce+return. Avoid printf/%.1f — AOT SIGSEGV (#29319).
error_reporting(0);
echo is_nan(fmod(5.0, null)) ? "NAN\n" : "fail\n";
echo var_export(fmod(null, 2.0), true), "\n";
echo var_export(fdiv(5.0, null), true), "\n";
echo var_export(fdiv(null, 2.0), true), "\n";
echo var_export(hypot(null, 3.0), true), "\n";
echo var_export(atan2(null, 1.0), true), "\n";
--EXPECT--
NAN
0
INF
0
3
0
