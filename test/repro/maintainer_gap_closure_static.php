<?php
$g = function (): int {
    static $n = 0;
    return ++$n;
};
var_dump($g(), $g());
