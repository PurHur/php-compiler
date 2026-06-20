--TEST--
Language: __DIR__/__FILE__/__LINE__ in closures — compile and run (#10181, Zend/zend_compile.c)
--RUNFILE--
closure_magic_dir/run.php
--EXPECTF--
%S
%S
%d
