<?php

declare(strict_types=1);

class Box {
    public string $label {
        get => strtoupper($this->label);
    }
    private string $label = 'hi';
}

$rp = new ReflectionProperty(Box::class, 'label');

if (!method_exists($rp, 'hasHooks')) {
    echo "fail: hasHooks missing\n";
    exit(1);
}
if (!$rp->hasHooks()) {
    echo "fail: hasHooks should be true for hooked property\n";
    exit(1);
}
if (!$rp->hasHook(PropertyHookType::Get)) {
    echo "fail: hasHook(Get) should be true\n";
    exit(1);
}
if ($rp->hasHook(PropertyHookType::Set)) {
    echo "fail: hasHook(Set) should be false\n";
    exit(1);
}

echo "ok\n";
