--TEST--
filter_var_array() definition arrays with flags/options (#22839, #22852)
--FILE--
<?php
declare(strict_types=1);

$data = ['age' => '15', 'name' => 'x'];
$args = [
    'age' => [
        'filter' => FILTER_VALIDATE_INT,
        'flags' => FILTER_FORCE_ARRAY,
        'options' => ['min_range' => 1, 'max_range' => 120],
    ],
    'name' => ['filter' => FILTER_SANITIZE_FULL_SPECIAL_CHARS],
];
var_export(filter_var_array($data, $args));
echo "\n";

var_export(filter_var_array($data, ['age' => FILTER_VALIDATE_INT]));
echo "\n";
--EXPECT--
array (
  'age' => array (
    0 => 15,
  ),
  'name' => 'x',
)
array (
  'age' => 15,
)
