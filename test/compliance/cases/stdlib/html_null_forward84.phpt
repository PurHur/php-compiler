--TEST--
stdlib htmlspecialchars/htmlentities decode family null TypeError on 8.4 forward profile (#19296, ext/standard/html.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
foreach ([
    'htmlspecialchars' => static fn () => htmlspecialchars(null),
    'htmlentities' => static fn () => htmlentities(null),
    'htmlspecialchars_decode' => static fn () => htmlspecialchars_decode(null),
    'html_entity_decode' => static fn () => html_entity_decode(null),
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
htmlspecialchars_decode: htmlspecialchars_decode(): Argument #1 ($string) must be of type string, null given
html_entity_decode: html_entity_decode(): Argument #1 ($string) must be of type string, null given
