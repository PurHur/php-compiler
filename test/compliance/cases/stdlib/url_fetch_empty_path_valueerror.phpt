--TEST--
stdlib get_meta_tags()/get_headers() empty URL — ValueError Path must not be empty (#14076, ext/standard/head.c)
--FILE--
<?php
foreach (['get_meta_tags', 'get_headers'] as $fn) {
    try {
        $fn('');
        echo $fn, ": miss\n";
    } catch (ValueError $e) {
        echo $fn, ':', $e->getMessage(), "\n";
    }
}
?>
--EXPECT--
get_meta_tags:Path must not be empty
get_headers:Path must not be empty
