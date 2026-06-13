--TEST--
stdlib basename()/dirname() — TypeError for wrong operand types (#4715, ext/standard/basename.c, file.c)
--RUNFILE--
path_functions_type_error_run.php
--EXPECT--
basename_suffix: TypeError: basename(): Argument #2 ($suffix) must be of type string, array given
dirname_levels: TypeError: dirname(): Argument #2 ($levels) must be of type int, array given
