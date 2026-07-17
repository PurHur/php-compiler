--TEST--
AOT: basename null — coerce on 8.4 forward profile (#19997)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
echo var_export(basename(null), true), "\n";
--EXPECT--
''
