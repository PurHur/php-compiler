--TEST--
AOT parse_ini_string() ${ENV} interpolation (#12928)
--FILE--
<?php
declare(strict_types=1);

var_export(parse_ini_string('a="${x}"' . "\n" . 'b=2'));
echo "\n";
--EXPECT--
array (
  'a' => '',
  'b' => '2',
)
