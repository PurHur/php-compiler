--TEST--
Pipe + first-class callable assignment yields Closure (issue #4943)
--FILE--
<?php
$fn = "hi" |> strtoupper(...);
echo $fn instanceof Closure ? "closure\n" : get_debug_type($fn), "\n";
echo $fn(), "\n";
echo "hi" |> strtoupper(...);
echo "\n";
echo "hi" |> strtoupper(...) |> strlen(...);
--EXPECT--
closure

HI
HI
2
