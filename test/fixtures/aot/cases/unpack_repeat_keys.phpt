--TEST--
AOT: unpack() repeated format embedded name keys (issue #10413)
--FILE--
<?php
var_export(unpack('a2a2', 'abcd'));
echo "\n";
var_export(unpack('h2h2', 'abcd'));
echo "\n";
--EXPECT--
array (
  'a2' => 'ab',
)
array (
  'h2' => '16',
)
