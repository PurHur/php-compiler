<?php

declare(strict_types=1);

namespace PHPCompiler\ext\uuid;

use PHPCompiler\Frame;

/**
 * uuid_mac() — php/pecl-networking-uuid uuid.c (#22228).
 */
final class uuid_mac extends UuidFunction
{
    public function __construct()
    {
        parent::__construct('uuid_mac');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(
                'uuid_mac() expects exactly 1 argument, '.$argc.' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $uuid = UuidStringArg::require($frame, 0, 'uuid_mac', 'uuid');
        $result = VmUuid::mac($uuid);
        $frame->returnVar->string($result);
    }
}
