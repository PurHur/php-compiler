<?php

declare(strict_types=1);

namespace PHPCompiler\ext\uuid;

use PHPCompiler\Frame;

/**
 * uuid_parse() — php/pecl-networking-uuid uuid.c (#22228).
 */
final class uuid_parse extends UuidFunction
{
    public function __construct()
    {
        parent::__construct('uuid_parse');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(
                'uuid_parse() expects exactly 1 argument, '.$argc.' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $uuid = UuidStringArg::require($frame, 0, 'uuid_parse', 'uuid');
        $result = VmUuid::parse($uuid);
        $frame->returnVar->string($result);
    }
}
