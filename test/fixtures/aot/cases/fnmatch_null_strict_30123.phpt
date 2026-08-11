--TEST--
AOT: fnmatch(null) under strict_types TypeError (#30123; re-#20139)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
declare(strict_types=1);
try {
    var_export(fnmatch(null, 'a'));
    echo " bad:pattern:uncaught\n";
} catch (TypeError $e) {
    echo 'ok:pattern:', $e->getMessage(), "\n";
}
try {
    var_export(fnmatch('a', null));
    echo " bad:filename:uncaught\n";
} catch (TypeError $e) {
    echo 'ok:filename:', $e->getMessage(), "\n";
}
--EXPECT--
ok:pattern:fnmatch(): Argument #1 ($pattern) must be of type string, null given
ok:filename:fnmatch(): Argument #2 ($filename) must be of type string, null given
