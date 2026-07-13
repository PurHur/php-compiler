<?php

declare(strict_types=1);

namespace PHPCompiler\ext\wddx;

use PHPCompiler\ext\standard\VmScope;
use PHPCompiler\Frame;
use PHPCompiler\VM\Variable;

/** wddx_serialize_vars() — struct packet from variable names (php-src ext/wddx/wddx.c; #6327). */
final class wddx_serialize_vars extends WddxFunction
{
    public function __construct()
    {
        parent::__construct('wddx_serialize_vars');
    }

    public function execute(Frame $frame): void
    {
        $this->requireAtLeastArgCount($frame, 'wddx_serialize_vars', 1);
        if (null === $frame->returnVar) {
            return;
        }

        $named = [];
        $table = VmScope::compact($frame);
        foreach ($table->iterateKeyed(true) as [$key, $value]) {
            $named[] = [$key->resolveIndirect()->toString(null), $value];
        }

        try {
            $xml = VmWddx::serializeNamedVars($named);
        } catch (\Error $e) {
            throw $e;
        }
        $frame->returnVar->string($xml);
    }
}
