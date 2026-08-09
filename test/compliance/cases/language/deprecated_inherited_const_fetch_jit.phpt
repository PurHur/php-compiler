--TEST--
Language: #[\Deprecated] inherited class const cites declaring class A::X (JIT, #29382)
--ENV--
PHP_COMPILER_PROFILE=8.4
--RUNFILE--
deprecated_inherited_const_fetch_run.php
--EXPECTF--
1
Constant A::X is deprecated, gone
dep
