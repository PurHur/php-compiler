<?php
$cases = [
    'str_repeat' => fn() => str_repeat(null, 1),
    'str_shuffle' => fn() => str_shuffle(null),
    'ucfirst' => fn() => ucfirst(null),
    'lcfirst' => fn() => lcfirst(null),
    'ucwords' => fn() => ucwords(null),
];
foreach ($cases as $name => $call) {
    try {
        $r = $call();
        echo "$name: uncaught ".var_export($r, true)."\n";
    } catch (TypeError $e) {
        echo "$name: TypeError\n";
    }
}
echo "ok\n";
