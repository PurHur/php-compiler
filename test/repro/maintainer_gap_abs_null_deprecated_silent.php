<?php

error_reporting(E_ALL);

foreach (['abs', 'round', 'ceil', 'floor'] as $fn) {
    $result = $fn(null);
    echo $fn, '(null)=', var_export($result, true), "\n";
}
