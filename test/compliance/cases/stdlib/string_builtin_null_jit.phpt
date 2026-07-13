--TEST--
stdlib string builtins — null $string coerces to empty string JIT (#18483, ext/standard/string.c, html.c)
--JIT--
--FILE--
<?php
foreach ([
    'htmlspecialchars' => static fn () => htmlspecialchars(null),
    'htmlentities' => static fn () => htmlentities(null),
    'addslashes' => static fn () => addslashes(null),
    'stripslashes' => static fn () => stripslashes(null),
    'addcslashes' => static fn () => addcslashes(null, 'a'),
    'strip_tags' => static fn () => strip_tags(null),
    'wordwrap' => static fn () => wordwrap(null),
] as $name => $call) {
    try {
        $call();
        echo "{$name}: uncaught\n";
    } catch (TypeError $e) {
        echo $name, ': TypeError', "\n";
    }
}
--EXPECT--
htmlspecialchars: uncaught
htmlentities: uncaught
addslashes: uncaught
stripslashes: uncaught
addcslashes: uncaught
strip_tags: uncaught
wordwrap: uncaught
