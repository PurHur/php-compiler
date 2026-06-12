<?php
// PHP 8.4: array_find callback receives ($value, $key)
$a = ['x' => 10, 'y' => 20];
$found = array_find($a, function ($v, $k) {
    return $k === 'y';
});
var_dump($found);
