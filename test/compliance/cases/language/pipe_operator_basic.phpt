--TEST--
PHP 8.5 pipe operator basic and chained (issue #7219)
--FILE--
<?php
echo 1 |> strval, "\n";
$result = "PHP Rocks"
    |> htmlentities(...)
    |> str_split(...)
    |> (fn($x) => array_map(strtoupper(...), $x));
print_r($result);
--EXPECT--
1
Array
(
    [0] => P
    [1] => H
    [2] => P
    [3] => 
    [4] => R
    [5] => O
    [6] => C
    [7] => K
    [8] => S
)
