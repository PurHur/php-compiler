<?php

declare(strict_types=1);

namespace PHPCompiler\ext\wddx;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\VM\ResourceState;
use PHPCompiler\VM\ResourceSupport;

/** wddx_packet_start() — open incremental WDDX struct packet (pecl-text-wddx wddx.c; #27858). */
final class wddx_packet_start extends WddxFunction
{
    public function __construct()
    {
        parent::__construct('wddx_packet_start');
    }

    public function execute(Frame $frame): void
    {
        $this->requireAtMostArgCount($frame, 'wddx_packet_start', 1);
        if (null === $frame->returnVar) {
            return;
        }
        $comment = null;
        if (isset($frame->calledArgs[0])) {
            $comment = VmString::coerceStringBuiltinArg(
                $frame->calledArgs[0],
                'wddx_packet_start',
                1,
                'comment'
            );
            if ('' === $comment) {
                $comment = null;
            }
        }
        $id = VmWddx::packetStart($comment);
        if (null === $frame->vmContext) {
            throw new \LogicException('wddx_packet_start() requires an active VM context');
        }
        ResourceSupport::wrap($frame->returnVar, $id, ResourceState::KIND_WDDX_PACKET, $frame->vmContext);
    }
}
