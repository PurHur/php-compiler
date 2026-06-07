--TEST--
stdlib strip_tags()/htmlentities()/nl2br()/htmlspecialchars() JIT — backed enum case TypeError (#6612)
--FILE--
<?php
enum E: string { case A = "x"; }

$tests = [
    ['strip_tags', static fn () => strip_tags(E::A)],
    ['htmlentities', static fn () => htmlentities(E::A)],
    ['html_entity_decode', static fn () => html_entity_decode(E::A)],
    ['htmlspecialchars_decode', static fn () => htmlspecialchars_decode(E::A)],
    ['nl2br', static fn () => nl2br(E::A)],
    ['htmlspecialchars', static fn () => htmlspecialchars(E::A)],
];

foreach ($tests as [$name, $fn]) {
    try {
        $fn();
        echo $name, ": uncaught\n";
    } catch (TypeError $e) {
        echo $name, ': ', $e->getMessage(), "\n";
    }
}
--EXPECT--
strip_tags: strip_tags(): Argument #1 ($string) must be of type string, E given
htmlentities: htmlentities(): Argument #1 ($string) must be of type string, E given
html_entity_decode: html_entity_decode(): Argument #1 ($string) must be of type string, E given
htmlspecialchars_decode: htmlspecialchars_decode(): Argument #1 ($string) must be of type string, E given
nl2br: nl2br(): Argument #1 ($string) must be of type string, E given
htmlspecialchars: htmlspecialchars(): Argument #1 ($string) must be of type string, E given
