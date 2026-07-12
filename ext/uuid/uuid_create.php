<?php

declare(strict_types=1);

namespace PHPCompiler\ext\uuid;

use PHPCompiler\ext\standard\VmMath;
use PHPCompiler\Frame;
use PHPCompiler\VM\Variable;

/**
 * uuid_create() — generate RFC 4122 UUID string (php/pecl-networking-uuid; issue #5910).
 */
final class uuid_create extends UuidFunction
{
    public function __construct()
    {
        parent::__construct('uuid_create');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 1) {
            throw new \ArgumentCountError(
                'uuid_create() accepts at most 1 argument, '.$argc.' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $type = UuidConstants::UUID_TYPE_DEFAULT;
        if (1 === $argc) {
            $type = VmMath::parseIntBuiltinArgForFrame($frame, 0, 'uuid_create', 1, 'uuid_type');
        }
        $frame->returnVar->string(VmUuid::create($type));
    }
}
