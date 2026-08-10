<?php

foreach (['stristr', 'strchr', 'strrchr'] as $fn) {
    $r = $fn(null, 'a');
    echo $fn, ':';
    var_export($r);
    echo "\n";
}
