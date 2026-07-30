--TEST--
Language: isset()/empty() before sibling call in array literal must not clobber args (#25188, Zend/zend_execute.c)
--FILE--
<?php
$a = ['x' => null];
var_export([isset($a['x']), array_key_exists('x', $a)]);
echo "\n";
var_export([empty($a['x']), array_key_exists('x', $a)]);
echo "\n";
$s = 'ab';
var_export([isset($s[0]), strlen($s)]);
echo "\n";
var_export([isset($a['x']), count($a)]);
echo "\n";
var_export([array_key_exists('x', $a), isset($a['x'])]);
echo "\n";
echo var_export(isset($a['x']), true), "\n";
echo var_export(empty($a['x']), true), "\n";
--EXPECT--
array (
  0 => false,
  1 => true,
)
array (
  0 => true,
  1 => true,
)
array (
  0 => true,
  1 => 2,
)
array (
  0 => false,
  1 => 1,
)
array (
  0 => true,
  1 => false,
)
false
true
