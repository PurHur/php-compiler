<?php
$cases = [
    'str_repeat' => static fn () => str_repeat(null, 1),
    'str_shuffle' => static fn () => str_shuffle(null),
    'ucfirst' => static fn () => ucfirst(null),
    'lcfirst' => static fn () => lcfirst(null),
    'ucwords' => static fn () => ucwords(null),
];
foreach ($cases as $name => $call) {
    try {
        $r = $call();
        echo "$name: uncaught ", var_export($r, true), "\n";
    } catch (TypeError $e) {
        echo "$name: ", $e->getMessage(), "\n";
    }
}
