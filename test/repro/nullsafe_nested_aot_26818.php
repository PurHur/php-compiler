<?php
// #26818 — nested ?-> + ?? must match Zend on AOT (n|5), not mid-block verify fail / empty echo.
$a = null;
echo $a?->x ?? "n";
echo "|";
$b = (object)["c" => (object)["d" => 5]];
echo $b?->c?->d;
echo "\n";
