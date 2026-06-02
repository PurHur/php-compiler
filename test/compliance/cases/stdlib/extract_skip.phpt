--TEST--
stdlib extract() with EXTR_SKIP does not overwrite existing locals
--FILE--
<?php
$name = 'keep';
extract(array('name' => 'new'), EXTR_SKIP);
echo $name, "\n";
--EXPECT--
keep
