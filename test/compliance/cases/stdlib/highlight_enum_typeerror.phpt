--TEST--
stdlib highlight_string()/highlight_file()/show_source() — enum case TypeError (#6486, ext/standard/url.c)
--FILE--
<?php
enum E: string { case A = 'x'; }
foreach (['highlight_string', 'highlight_file', 'show_source'] as $fn) {
    try {
        $fn(E::A);
        echo $fn, " uncaught\n";
    } catch (TypeError $e) {
        echo $e->getMessage(), "\n";
    }
}
--EXPECT--
highlight_string(): Argument #1 ($string) must be of type string, E given
highlight_file(): Argument #1 ($filename) must be of type string, E given
show_source(): Argument #1 ($filename) must be of type string, E given
