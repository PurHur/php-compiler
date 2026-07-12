--TEST--
stdlib define() runtime array constant — var_export fetches value not define() bool (#17872, basic_functions.c)
--FILE--
<?php

declare(strict_types=1);

define('ARR', [1, 2]);
var_export(ARR);
echo "\n";
define('O', new stdClass());
var_export(O);
echo "\n";
const ARR2 = [1, 2];
var_export(ARR2);
echo "\n";
echo defined('ARR') ? "defined\n" : "missing\n";
--EXPECT--
array (
  0 => 1,
  1 => 2,
)
(object) array(
)
array (
  0 => 1,
  1 => 2,
)
defined
