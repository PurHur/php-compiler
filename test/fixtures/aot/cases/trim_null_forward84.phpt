--TEST--
AOT: trim null — coerce on 8.4 forward profile (#19983)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
echo var_export(trim(null), true), "\n";
--EXPECT--
''
