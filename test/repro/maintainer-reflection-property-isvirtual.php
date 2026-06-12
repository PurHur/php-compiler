<?php
class C {
    public string $title {
        get => 'hook';
    }
}

$p = new ReflectionProperty(C::class, 'title');
echo 'isVirtual: ', var_export(method_exists($p, 'isVirtual'), true), ' ', var_export($p->isVirtual(), true), "\n";
echo 'isDynamic: ', var_export(method_exists($p, 'isDynamic'), true), ' ', var_export($p->isDynamic(), true), "\n";
echo 'getMangledName: ', var_export(method_exists($p, 'getMangledName'), true), "\n";
echo 'hasHook: ', var_export(method_exists($p, 'hasHook'), true), "\n";
echo 'getHooks: ', var_export(method_exists($p, 'getHooks'), true), "\n";
