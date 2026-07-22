--TEST--
ReflectionProperty::isLazy() — lazy ghost property probe (issue #6515, ext/reflection/php_reflection.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
class Entity {
    public string $name;
    public int $version;
}
$ref = new ReflectionClass(Entity::class);
$ghost = $ref->newLazyGhost(function (Entity $e) {
    $e->name = 'loaded';
});
$nameProp = $ref->getProperty('name');
$verProp = $ref->getProperty('version');
var_export($nameProp->isLazy($ghost));
echo "\n";
var_export($verProp->isLazy($ghost));
echo "\n";
$ghost->name;
var_export($nameProp->isLazy($ghost));
echo "\n";
var_export($verProp->isLazy($ghost));
echo "\n";

class StaticOnly {
    public static string $id = 'x';
}
$sp = new ReflectionProperty(StaticOnly::class, 'id');
var_export($sp->isLazy(new StaticOnly()));
echo "\n";

$plain = new Entity();
$plain->name = 'n';
$plain->version = 1;
var_export($nameProp->isLazy($plain));
echo "\n";
--EXPECT--
true
true
false
true
false
false
