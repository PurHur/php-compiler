<?php

declare(strict_types=1);

namespace PHPCompiler\ext\wddx;

use PHPCompiler\ext\standard\VmScope;
use PHPCompiler\Frame;
use PHPCompiler\VM\ResourceState;
use PHPCompiler\VM\ResourceSupport;
use PHPCompiler\VM\Variable;

/** wddx_add_vars() — append named locals into an open WDDX packet (pecl-text-wddx wddx.c; #27858). */
final class wddx_add_vars extends WddxFunction
{
    public function __construct()
    {
        parent::__construct('wddx_add_vars');
    }

    public function execute(Frame $frame): void
    {
        $this->requireAtLeastArgCount($frame, 'wddx_add_vars', 2);
        if (null === $frame->returnVar) {
            return;
        }
        $packetVar = $frame->calledArgs[0]->resolveIndirect();
        $handle = self::packetHandle($packetVar);
        if (null === $handle) {
            $frame->returnVar->bool(false);

            return;
        }

        $named = [];
        $table = VmScope::compactArgsFrom($frame, 1);
        foreach ($table->iterateKeyed(true) as [$key, $value]) {
            $named[] = [$key->resolveIndirect()->toString(null), $value];
        }

        $frame->returnVar->bool(VmWddx::packetAddNamedVars($handle, $named));
    }

    private static function packetHandle(Variable $packetVar): ?int
    {
        $state = ResourceSupport::stateFromVariable($packetVar);
        if (null === $state || ResourceState::KIND_WDDX_PACKET !== $state->kind || $state->handle <= 0) {
            return null;
        }
        if (!VmWddx::isValidPacketHandle($state->handle)) {
            return null;
        }

        return $state->handle;
    }
}
