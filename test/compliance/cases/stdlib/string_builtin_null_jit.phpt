--TEST--
stdlib typed string builtins — null $string TypeError JIT (#18190, ext/standard/string.c, html.c)
--JIT--
--FILE--
<?php
foreach ([
    'htmlspecialchars' => static fn () => htmlspecialchars(null),
    'htmlentities' => static fn () => htmlentities(null),
    'addcslashes' => static fn () => addcslashes(null, 'a'),
    'strip_tags' => static fn () => strip_tags(null),
] as $name => $call) {
    try {
        $call();
        echo "{$name}: uncaught\n";
    } catch (TypeError $e) {
        echo $name, ': TypeError', "\n";
    }
}
--EXPECT--
htmlspecialchars: TypeError
htmlentities: TypeError
addcslashes: TypeError
strip_tags: TypeError
