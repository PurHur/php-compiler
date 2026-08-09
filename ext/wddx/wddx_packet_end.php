<?php

declare(strict_types=1);

namespace PHPCompiler\ext\wddx;

use PHPCompiler\Frame;
use PHPCompiler\VM\ResourceState;
use PHPCompiler\VM\ResourceSupport;

/** wddx_packet_end() — finish WDDX packet XML and close the resource (pecl-text-wddx wddx.c; #27858). */
final class wddx_packet_end extends WddxFunction
{
    public function __construct()
    {
        parent::__construct('wddx_packet_end');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'wddx_packet_end', 1);
        if (null === $frame->returnVar) {
            return;
        }
        $packetVar = $frame->calledArgs[0]->resolveIndirect();
        $state = ResourceSupport::stateFromVariable($packetVar);
        if (null === $state || ResourceState::KIND_WDDX_PACKET !== $state->kind || $state->handle <= 0) {
            $frame->returnVar->bool(false);

            return;
        }
        $xml = VmWddx::packetEnd($state->handle, $packetVar);
        if (false === $xml) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($xml);
    }
}
