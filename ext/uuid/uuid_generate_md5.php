<?php

declare(strict_types=1);

namespace PHPCompiler\ext\uuid;

use PHPCompiler\Frame;

/**
 * uuid_generate_md5() — RFC 4122 name-based UUID v3 (php/pecl-networking-uuid; #27836).
 */
final class uuid_generate_md5 extends UuidFunction
{
    public function __construct()
    {
        parent::__construct('uuid_generate_md5');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(
                'uuid_generate_md5() expects exactly 2 arguments, '.$argc.' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $ns = UuidStringArg::require($frame, 0, 'uuid_generate_md5', 'uuid_ns');
        $name = UuidStringArg::require($frame, 1, 'uuid_generate_md5', 'name');
        $frame->returnVar->string(VmUuid::generateMd5($ns, $name));
    }
}
