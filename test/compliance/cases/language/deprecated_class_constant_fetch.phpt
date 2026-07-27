--TEST--
Language: #[\Deprecated] on class constants emits E_USER_DEPRECATED on fetch (#6962)
--ENV--
PHP_COMPILER_PROFILE=8.4
--RUNFILE--
deprecated_class_constant_fetch_run.php
--EXPECTF--
1
Constant C::X is deprecated since 8.4, use NEW instead
dep
