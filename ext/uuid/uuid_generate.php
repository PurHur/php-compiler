<?php

declare(strict_types=1);

namespace PHPCompiler\ext\uuid;

use PHPCompiler\Frame;
use PHPCompiler\VM\Variable;

/**
 * uuid_generate() — fill out-param with a new UUID (php/pecl-networking-uuid; issue #5910).
 */
final class uuid_generate extends UuidFunction
{
    public function __construct()
    {
        parent::__construct('uuid_generate');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(
                'uuid_generate() expects exactly 1 argument, '.$argc.' given'
            );
        }
        $uuid = VmUuid::create(UuidConstants::UUID_TYPE_DEFAULT);
        $out = new Variable();
        $out->string($uuid);
        $frame->calledArgs[0]->byRefTarget()->copyFrom($out);
        if (null !== $frame->returnVar) {
            $frame->returnVar->null();
        }
    }
}
