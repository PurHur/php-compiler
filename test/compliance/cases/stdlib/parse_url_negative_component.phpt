--TEST--
stdlib parse_url() invalid component ValueError (#10645)
--FILE--
<?php
try {
    parse_url('http://example.com/path', -1);
    echo "uncaught\n";
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
parse_url(): Argument #2 ($component) must be a valid URL component identifier, -1 given
