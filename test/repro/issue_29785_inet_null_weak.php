<?php

foreach (['ip2long', 'inet_pton', 'inet_ntop'] as $fn) {
    $r = @$fn(null);
    echo $fn, ':';
    var_export($r);
    echo "\n";
}
