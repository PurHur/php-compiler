<?php

declare(strict_types=1);

class Box {
    public string $label {
        get => strtoupper($this->label);
        set (string $v) { $this->label = $v; }
    }
    private string $label = 'hi';
}

$rp = new ReflectionProperty(Box::class, 'label');

echo method_exists($rp, 'getHooks') ? "getHooks yes\n" : "getHooks no\n";
echo method_exists($rp, 'setHook') ? "setHook yes\n" : "setHook no\n";
echo method_exists($rp, 'getHook') ? "getHook yes\n" : "getHook no\n";

if (method_exists($rp, 'getHooks')) {
    $hooks = $rp->getHooks();
    ksort($hooks);
    echo implode(',', array_keys($hooks)), "\n";
}

if (method_exists($rp, 'getHook')) {
    $getHook = $rp->getHook(PropertyHookType::Get);
    echo $getHook instanceof ReflectionMethod ? "getHook-rm\n" : "getHook-missing\n";
}
