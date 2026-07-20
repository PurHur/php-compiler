--TEST--
PROFILE=8.4: htmlspecialchars/htmlentities/nl2br/addslashes null TypeError (#21351)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
foreach ([
    'htmlspecialchars' => static fn () => htmlspecialchars(null),
    'htmlentities' => static fn () => htmlentities(null),
    'nl2br' => static fn () => nl2br(null),
    'addslashes' => static fn () => addslashes(null),
] as $label => $factory) {
    try {
        $factory();
        echo "$label: uncaught\n";
    } catch (TypeError $e) {
        echo $label.': '.$e->getMessage()."\n";
    }
}
--EXPECT--
htmlspecialchars: htmlspecialchars(): Argument #1 ($string) must be of type string, null given
htmlentities: htmlentities(): Argument #1 ($string) must be of type string, null given
nl2br: nl2br(): Argument #1 ($string) must be of type string, null given
addslashes: addslashes(): Argument #1 ($string) must be of type string, null given
