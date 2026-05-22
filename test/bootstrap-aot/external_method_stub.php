<?php

declare(strict_types=1);

/**
 * JIT external method stub smoke test (issue #579).
 * Method body is not in the bundle; compile must not fail on TYPE_METHODCALL_INIT.
 */

class VendorLike
{
    public function addVisitor(mixed $visitor): void
    {
    }
}

$receiver = new VendorLike();
$receiver->addVisitor(null);
echo "ok\n";
