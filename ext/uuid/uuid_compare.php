<?php

declare(strict_types=1);

namespace PHPCompiler\ext\uuid;

use PHPCompiler\Frame;

/**
 * uuid_compare() — php/pecl-networking-uuid uuid.c (#22228).
 */
final class uuid_compare extends UuidFunction
{
    public function __construct()
    {
        parent::__construct('uuid_compare');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(
                'uuid_compare() expects exactly 2 arguments, '.$argc.' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $a = UuidStringArg::require($frame, 0, 'uuid_compare', 'uuid1');
        $b = UuidStringArg::require($frame, 1, 'uuid_compare', 'uuid2');
        $frame->returnVar->int(VmUuid::compare($a, $b));
    }
}
