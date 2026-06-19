--TEST--
Language: __DIR__ / __FILE__ / __LINE__ script magic constants (#9833, zend_compile.c)
--RUNFILE--
magic_dir_file/script.php
--EXPECTF--
%S
%S
4
