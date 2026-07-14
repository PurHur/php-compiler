--TEST--
opendir/mkdir/rmdir/chdir null path coercion on 8.4 forward profile (#18869, ext/standard/dir.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
echo 'opendir(null)=' . var_export(@opendir(null), true) . "\n";
echo 'mkdir(null)=' . var_export(@mkdir(null), true) . "\n";
echo 'rmdir(null)=' . var_export(@rmdir(null), true) . "\n";
echo 'chdir(null)=' . var_export(@chdir(null), true) . "\n";
--EXPECT--
opendir(null)=false
mkdir(null)=false
rmdir(null)=false
chdir(null)=false
