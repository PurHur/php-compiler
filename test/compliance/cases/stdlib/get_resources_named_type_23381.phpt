--TEST--
stdlib get_resources named $type (#23381, basic_functions.stub.php)
--FILE--
<?php
$r = new ReflectionFunction('get_resources');
$p = $r->getParameters()[0];
echo 'name=', $p->getName(), PHP_EOL;
echo 'optional=', $p->isOptional() ? '1' : '0', PHP_EOL;
echo 'type=', $p->hasType() ? (string) $p->getType() : 'untyped', PHP_EOL;
echo 'default=', $p->isDefaultValueAvailable() ? var_export($p->getDefaultValue(), true) : 'n/a', PHP_EOL;
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
name=type
optional=1
type=?string
default=NULL
named:ok
legacy:Unknown named parameter $resource_type
