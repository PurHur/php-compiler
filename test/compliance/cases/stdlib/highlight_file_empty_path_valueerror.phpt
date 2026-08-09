--TEST--
stdlib highlight_file()/show_source() empty path — ValueError Path must not be empty (#14075, ext/standard/url.c)
--FILE--
<?php
foreach (['highlight_file', 'show_source'] as $fn) {
    try {
        @$fn('');
        echo $fn, ": miss\n";
    } catch (ValueError $e) {
        echo $fn, ':', $e->getMessage(), "\n";
    }
}
?>
--EXPECT--
highlight_file:Path must not be empty
show_source:Path must not be empty
