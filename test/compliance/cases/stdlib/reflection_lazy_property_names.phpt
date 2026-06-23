--TEST--
ReflectionClass::getLazyPropertyNames() — lazy-eligible property names (issue #6606, ext/reflection/php_reflection.c)
--FILE--
<?php
var_export(method_exists(ReflectionClass::class, 'getLazyPropertyNames'));
echo "\n";

class Plain {
    public string $id;
}
$plain = new ReflectionClass(Plain::class);
var_export($plain->getLazyPropertyNames());
echo "\n";

class Svc {
    use LazyGhostTrait;
    public string $id;
    public int $version;
}
$svc = new ReflectionClass(Svc::class);
$names = $svc->getLazyPropertyNames();
sort($names);
echo implode(',', $names), "\n";

class Hooked {
    use LazyGhostTrait;
    public string $label {
        get => $this->label;
        set => $this->label = $value;
    }
    private string $label = '';
    public int $count;
}
$hooked = new ReflectionClass(Hooked::class);
$hookNames = $hooked->getLazyPropertyNames();
sort($hookNames);
echo implode(',', $hookNames), "\n";
--EXPECT--
true
array (
)
id,version
count,label
