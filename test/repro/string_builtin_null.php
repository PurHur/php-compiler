<?php
// Issue #18483 — Z_PARAM_STR null coerces to '' (ext/standard/string.c, html.c).
$tests = [
    'htmlspecialchars' => static fn () => htmlspecialchars(null),
    'htmlentities' => static fn () => htmlentities(null),
    'strtolower' => static fn () => strtolower(null),
    'addslashes' => static fn () => addslashes(null),
    'stripslashes' => static fn () => stripslashes(null),
    'addcslashes' => static fn () => addcslashes(null, 'a'),
    'trim' => static fn () => trim(null),
    'strip_tags' => static fn () => strip_tags(null),
    'wordwrap' => static fn () => wordwrap(null),
];
foreach ($tests as $name => $call) {
    try {
        $call();
        echo "{$name}: uncaught\n";
    } catch (TypeError $e) {
        echo $e->getMessage(), "\n";
    }
}
