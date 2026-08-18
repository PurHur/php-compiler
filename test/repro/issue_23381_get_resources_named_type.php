<?php
// #23381 — get_resources named $type (php-src ext/standard/basic_functions.stub.php)
$r = new ReflectionFunction('get_resources');
$p = $r->getParameters()[0];
echo 'name=', $p->getName(), "\n";
echo 'optional=', $p->isOptional() ? '1' : '0', "\n";
echo 'type=', $p->hasType() ? (string) $p->getType() : 'untyped', "\n";
echo 'default=', $p->isDefaultValueAvailable() ? var_export($p->getDefaultValue(), true) : 'n/a', "\n";
$a = get_resources(type: 'stream');
echo is_array($a) ? "named:ok\n" : "named:bad\n";
try {
    get_resources(resource_type: 'stream');
    echo "legacy:NO_THROW\n";
} catch (Throwable $e) {
    echo 'legacy:', $e->getMessage(), "\n";
}
