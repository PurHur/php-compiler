--TEST--
stdlib get_object_vars() — TypeError for null (#4813)
--RUNFILE--
get_object_vars_type_error_run.php
--EXPECT--
TypeError
get_object_vars(): Argument #1 ($object) must be of type object, null given
