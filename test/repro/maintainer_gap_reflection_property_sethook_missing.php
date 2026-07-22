<?php

declare(strict_types=1);

class HookedBox {
    public string $label {
        get => strtoupper($this->__label);
        set (string $v) { $this->__label = $v; }
    }

    private string $__label = 'hi';
}

$rp = new ReflectionProperty(HookedBox::class, 'label');
if (!method_exists($rp, 'setHook')) {
    echo "fail: setHook missing\n";
    exit(1);
}

$rp->setHook(PropertyHookType::Get, static fn () => 'replaced');
$obj = new HookedBox();
echo $obj->label, "\n";
$hooks = $rp->getHooks();
echo isset($hooks['get']) && $hooks['get'] instanceof Closure ? "runtime-get-closure\n" : "fail-hooks\n";
