<?php
// Guard #20225 — mb_strcut/mb_strimwidth/mb_detect_encoding null TypeError under PROFILE=8.4
$cases = [
    'mb_strcut' => static fn () => mb_strcut(null, 0),
    'mb_strimwidth' => static fn () => mb_strimwidth(null, 0, 5),
    'mb_detect_encoding' => static fn () => mb_detect_encoding(null),
];
foreach ($cases as $name => $fn) {
    try {
        $fn();
        echo "$name: uncaught\n";
    } catch (TypeError $e) {
        echo $name.': '.$e->getMessage()."\n";
    }
}
