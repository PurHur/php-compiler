<?php
// #25188 — isset()/empty() before another expr in the same array literal clobbers the next operand.
$a = ['x' => null];
var_export([isset($a['x']), array_key_exists('x', $a)]);
echo "\n";
var_export([empty($a['x']), array_key_exists('x', $a)]);
echo "\n";
$s = 'ab';
var_export([isset($s[0]), strlen($s)]);
echo "\n";
try {
    var_export([isset($a['x']), count($a)]);
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
var_export([array_key_exists('x', $a), isset($a['x'])]);
echo "\n";
