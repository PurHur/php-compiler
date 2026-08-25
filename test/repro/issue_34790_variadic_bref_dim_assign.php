<?php
// AOT: $args[0]= on &...$args must write through to callers (#34790; re-#27407 after #34684)
function bump_first(&...$args): void
{
    $args[0] = 99;
}
$x = 1;
bump_first($x);
echo $x, "\n";

function bump_two(&...$args): void
{
    $args[0] = 9;
    $args[1] = 8;
}
$a = 1;
$b = 2;
bump_two($a, $b);
echo $a, ",", $b, "\n";
