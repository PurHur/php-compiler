--TEST--
AOT json_encode() null $depth under strict_types — TypeError (#30486, ext/json/json.c)
--FILE--
<?php
declare(strict_types=1);
try {
    json_encode([], 0, null);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
json_encode(): Argument #3 ($depth) must be of type int, null given
