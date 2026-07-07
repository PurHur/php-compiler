--TEST--
stdlib highlight_file()/show_source() null under strict_types — TypeError not ValueError (#17174, ext/standard/url.c)
--FILE--
<?php
declare(strict_types=1);
foreach (['highlight_file', 'show_source'] as $fn) {
    try {
        $fn(null);
        echo $fn, ": miss\n";
    } catch (TypeError $e) {
        echo $fn, ':', $e->getMessage(), "\n";
    }
}
?>
--EXPECT--
highlight_file:highlight_file(): Argument #1 ($filename) must be of type string, null given
show_source:show_source(): Argument #1 ($filename) must be of type string, null given
