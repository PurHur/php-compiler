--TEST--
JIT: get_resources named $type (#23381, basic_functions.stub.php)
--FILE--
<?php
$a = get_resources(type: 'stream');
echo is_array($a) ? 'named:ok' : 'named:bad', PHP_EOL;
try {
    get_resources(resource_type: 'stream');
    echo 'legacy:NO_THROW', PHP_EOL;
} catch (Throwable $e) {
    echo 'legacy:', $e->getMessage(), PHP_EOL;
}
?>
--EXPECT--
named:ok
legacy:Unknown named parameter $resource_type
