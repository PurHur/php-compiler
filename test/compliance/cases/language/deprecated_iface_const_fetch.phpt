--TEST--
Language: #[\Deprecated] on interface constants emits E_USER_DEPRECATED via implementor (#29380)
--ENV--
PHP_COMPILER_PROFILE=8.4
--RUNFILE--
deprecated_iface_const_fetch_run.php
--EXPECTF--
1
Constant I::X is deprecated, use Y
dep
