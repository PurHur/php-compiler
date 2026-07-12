<?php
// Issue #18190 — typed string builtins reject null (ext/standard/string.c, html.c).
$tests = [
    'htmlspecialchars' => static fn () => htmlspecialchars(null),
    'htmlentities' => static fn () => htmlentities(null),
    'strtolower' => static fn () => strtolower(null),
    'addcslashes' => static fn () => addcslashes(null, 'a'),
    'trim' => static fn () => trim(null),
    'strip_tags' => static fn () => strip_tags(null),
];
foreach ($tests as $name => $call) {
    try {
        $call();
        echo "{$name}: uncaught\n";
    } catch (TypeError $e) {
        echo $e->getMessage(), "\n";
    }
}
