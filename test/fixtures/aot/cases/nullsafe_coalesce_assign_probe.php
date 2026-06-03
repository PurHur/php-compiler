<?php

declare(strict_types=1);

class NullsafeCoalesceProbeVm
{
    public $context;
}

class NullsafeCoalesceProbeFrame
{
    public $vmContext;
}

function nullsafe_coalesce_assign_probe(?NullsafeCoalesceProbeVm $vm, ?NullsafeCoalesceProbeFrame $frame): void
{
    $context = $vm?->context ?? $frame?->vmContext;
    if (null === $context) {
        echo "null\n";
    }
}
