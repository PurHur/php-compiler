<?php
foreach ([
    'basename' => fn() => basename(null),
    'dirname' => fn() => dirname(null),
    'pathinfo' => fn() => pathinfo(null),
] as $name => $call) {
    try {
        $r = $call();
        echo "$name: uncaught ".gettype($r)."\n";
    } catch (TypeError $e) {
        echo "$name: TypeError\n";
    }
}
echo "ok\n";
