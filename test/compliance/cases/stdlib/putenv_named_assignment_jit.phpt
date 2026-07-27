--TEST--
putenv named assignment argument (JIT, issue #23258)
--FILE--
<?php
var_export(putenv(assignment: 'PHPC_PUTENV_NAMED_23258_JIT=1'));
echo PHP_EOL;
echo getenv('PHPC_PUTENV_NAMED_23258_JIT'), PHP_EOL;
putenv('PHPC_PUTENV_NAMED_23258_JIT');
?>
--EXPECT--
true
1
