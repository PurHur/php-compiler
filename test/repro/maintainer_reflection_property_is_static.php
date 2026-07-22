<?php
/**
 * #22143 — ReflectionProperty::isStatic / isDefault / getModifiers (php-src-strict).
 * Repro: ./script/docker-exec.sh -- bash -lc 'php bin/vm.php test/repro/maintainer_reflection_property_is_static.php'
 */
class T
{
    public static $a = 1;
    public $b = 2;
    private $c;
    protected static $d;
    public readonly int $ro;

    public function __construct()
    {
        $this->ro = 1;
    }
}

echo 'method_exists isStatic=', method_exists(ReflectionProperty::class, 'isStatic') ? '1' : '0', "\n";
echo 'method_exists isDefault=', method_exists(ReflectionProperty::class, 'isDefault') ? '1' : '0', "\n";
echo 'method_exists getModifiers=', method_exists(ReflectionProperty::class, 'getModifiers') ? '1' : '0', "\n";

foreach (['a', 'b', 'c', 'd', 'ro'] as $name) {
    $p = new ReflectionProperty(T::class, $name);
    echo $name,
        ' static=', $p->isStatic() ? '1' : '0',
        ' default=', $p->isDefault() ? '1' : '0',
        ' mods=', $p->getModifiers(),
        "\n";
}

$o = new T();
$o->dyn = 1;
$dyn = new ReflectionProperty($o, 'dyn');
echo 'dyn static=', $dyn->isStatic() ? '1' : '0',
    ' default=', $dyn->isDefault() ? '1' : '0',
    ' mods=', $dyn->getModifiers(),
    "\n";
