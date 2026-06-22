<?php

declare(strict_types=1);

class MaintainerReflectionHookBase
{
    public string $label
    {
        get => 'from-hook';
    }
}

class MaintainerReflectionHookChild extends MaintainerReflectionHookBase
{
}

$o = new MaintainerReflectionHookChild();
$rp = new ReflectionProperty(MaintainerReflectionHookBase::class, 'label');
echo $rp->getValue($o), "\n";
