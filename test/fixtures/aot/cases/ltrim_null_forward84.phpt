--TEST--
AOT: ltrim null — coerce on 8.4 forward profile (#19983)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
echo var_export(ltrim(null), true), "\n";
--EXPECT--
''
