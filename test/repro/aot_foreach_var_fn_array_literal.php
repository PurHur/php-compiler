<?php
// #35075: foreach-bound $fn() must dispatch string-literal array callees under AOT.
error_reporting(E_ALL);
foreach (['strlen'] as $fn) {
    echo $fn('hi'), "\n";
}
foreach (['abs', 'round'] as $fn) {
    echo $fn, '=', var_export($fn(-2.5), true), "\n";
}
