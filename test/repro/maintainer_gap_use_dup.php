<?php
$a = 1;
$f = function () use ($a, $a) {
    return $a;
};
echo "accepted\n";
