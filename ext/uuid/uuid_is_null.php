<?php

declare(strict_types=1);

namespace PHPCompiler\ext\uuid;

use PHPCompiler\Frame;

/**
 * uuid_is_null() — php/pecl-networking-uuid uuid.c (#22228).
 */
final class uuid_is_null extends UuidFunction
{
    public function __construct()
    {
        parent::__construct('uuid_is_null');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(
                'uuid_is_null() expects exactly 1 argument, '.$argc.' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $uuid = UuidStringArg::require($frame, 0, 'uuid_is_null', 'uuid');
        $result = VmUuid::isNull($uuid);
        $frame->returnVar->bool($result);
    }
}
