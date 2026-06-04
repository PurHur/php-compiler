--TEST--
stdlib get_class() — JIT TypeError for null (#5456)
--JIT--
--RUNFILE--
get_class_null_type_error_run.php
--EXPECT--
TypeError
get_class(): Argument #1 ($object) must be of type object, null given
