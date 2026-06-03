<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$code = <<<'PHP'
<?php
class C {
    public int $x = 1;
}
echo "ok\n";
PHP;

$runtime = new PHPCompiler\Runtime();
$block = $runtime->parseAndCompile($code, 't.php');
$runtime->loadJit()->compile($block);
$objectType = $runtime->loadJitContext()->type->object;
$ref = new ReflectionObject($objectType);
$prop = $ref->getProperty('properties');
$prop->setAccessible(true);
$properties = $prop->getValue($objectType);
echo json_encode($properties, JSON_PRETTY_PRINT), "\n";
