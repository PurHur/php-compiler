<?php

var_export(mb_str_split('αβγ', 1));
echo "\n";
var_export(mb_str_split('ab', 2));
echo "\n";
var_export(mb_str_split('hello', 2, 'ASCII'));
echo "\n";
var_export(mb_str_split('', 1));
echo "\n";

try {
    mb_str_split('x', 0);
    echo "fail: no exception for length 0\n";
} catch (ValueError $e) {
    echo "length_zero=ValueError\n";
}
