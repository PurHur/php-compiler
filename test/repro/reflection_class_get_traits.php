<?php
declare(strict_types=1);

// Issue #22108 — ReflectionClass::getTraits() (ext/reflection/php_reflection.c)
trait T1
{
    public function a(): int
    {
        return 1;
    }
}

trait T2
{
    public function b(): int
    {
        return 2;
    }
}

class C
{
    use T1, T2;
}

class EmptyTraits
{
}

$r = new ReflectionClass(C::class);
if (!method_exists($r, 'getTraits')) {
    fwrite(STDERR, "FAIL: getTraits missing\n");
    exit(1);
}

$traits = $r->getTraits();
$keys = array_keys($traits);
sort($keys);
if ($keys !== ['T1', 'T2']) {
    fwrite(STDERR, 'FAIL keys='.json_encode($keys)."\n");
    exit(1);
}
foreach (['T1', 'T2'] as $name) {
    if (!isset($traits[$name]) || !($traits[$name] instanceof ReflectionClass)) {
        fwrite(STDERR, "FAIL type for $name\n");
        exit(1);
    }
    if ($traits[$name]->getName() !== $name) {
        fwrite(STDERR, "FAIL name for $name\n");
        exit(1);
    }
}

$empty = (new ReflectionClass(EmptyTraits::class))->getTraits();
if ($empty !== []) {
    fwrite(STDERR, 'FAIL empty='.json_encode($empty)."\n");
    exit(1);
}

echo 'keys=', json_encode($keys), "\n";
echo 'empty=', json_encode(array_keys($empty)), "\n";
echo "OK\n";
