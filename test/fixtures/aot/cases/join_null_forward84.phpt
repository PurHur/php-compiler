--TEST--
AOT: join(null) soft-null on 8.4 forward profile (#21210)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$sep = null;
echo join($sep, ['a']), "\n";
--EXPECT--
a
