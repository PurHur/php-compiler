<?php
function f() {
    static $n = 10;
    return $n++;
}
var_dump(f());
var_dump(f());

$c = function() {
    static $n = 10;
    return $n++;
};
var_dump($c());
var_dump($c());
