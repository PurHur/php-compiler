--TEST--
stdlib get_class() — TypeError for null (#5456, ext/standard/basic_functions.c)
--RUNFILE--
get_class_null_type_error_run.php
--EXPECT--
TypeError
get_class(): Argument #1 ($object) must be of type object, null given
