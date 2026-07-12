--TEST--
stdlib typed string builtins — null $string TypeError (#18190, ext/standard/string.c, html.c)
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
        echo $e->getMessage(), "\n";
    }
}
--EXPECT--
htmlspecialchars(): Argument #1 ($string) must be of type string, null given
htmlentities(): Argument #1 ($string) must be of type string, null given
addcslashes(): Argument #1 ($str) must be of type string, null given
strip_tags(): Argument #1 ($string) must be of type string, null given
