--TEST--
preg_grep() pattern:/array: named parameters (#10050, ext/pcre/pcre.stub.php)
--FILE--
<?php
declare(strict_types=1);

var_export(preg_grep(pattern: '/[0-9]/', array: ['a', '1']));
echo "\n";
--EXPECT--
array (
  1 => '1',
)
