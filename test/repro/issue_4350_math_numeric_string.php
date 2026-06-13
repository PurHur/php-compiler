<?php

foreach (['floor', 'ceil', 'round'] as $fn) {
    echo $fn, '("3.7")=', $fn('3.7'), "\n";
}
echo 'fmod("5","2")=', fmod('5', '2'), "\n";
