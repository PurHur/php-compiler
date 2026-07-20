--TEST--
AOT: implode(null) soft-null on 8.4 forward profile (#21210)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
// Variable null — literal null before array literal can take a different
// boxed path; variable form matches Zend soft-null separator (#21210).
$sep = null;
echo implode($sep, ['a', 'b']), "\n";
--EXPECT--
ab
