--TEST--
stdlib parse_url($url, null) TypeError under strict_types (#24942, ext/standard/url.c)
--FILE--
<?php
declare(strict_types=1);
try {
    parse_url('http://example.com/x', null);
    echo "no error\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
parse_url(): Argument #2 ($component) must be of type int, null given
