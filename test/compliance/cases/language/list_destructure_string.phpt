--TEST--
list() destructuring from string — NULL slots (#10486, supersedes #7461 TypeError)
--FILE--
<?php
[$a] = 'ab';
var_export($a);
echo "\n";
[$b, $c] = 'xy';
var_export([$b, $c]);
echo "\n";
--EXPECT--
NULL
array (
  0 => NULL,
  1 => NULL,
)
