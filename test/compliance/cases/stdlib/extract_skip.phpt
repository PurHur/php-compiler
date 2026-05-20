--TEST--
stdlib extract() with EXTR_SKIP does not overwrite existing locals
--FILE--
<?php
$name = 'keep';
$flags = 6;
extract(array('name' => 'new'), $flags);
echo $name, "\n";
--EXPECT--
keep
