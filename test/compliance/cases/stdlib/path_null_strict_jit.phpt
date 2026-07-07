--TEST--
stdlib file()/get_headers()/get_meta_tags() null under strict_types — JIT TypeError (#17179)
--FILE--
<?php
declare(strict_types=1);
foreach (['file', 'get_headers', 'get_meta_tags'] as $fn) {
    try {
        $fn(null);
        echo $fn, ": miss\n";
    } catch (TypeError $e) {
        echo $fn, ':', $e->getMessage(), "\n";
    }
}
?>
--EXPECT--
file:file(): Argument #1 ($filename) must be of type string, null given
get_headers:get_headers(): Argument #1 ($url) must be of type string, null given
get_meta_tags:get_meta_tags(): Argument #1 ($filename) must be of type string, null given
