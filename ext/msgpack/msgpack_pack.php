<?php

declare(strict_types=1);

namespace PHPCompiler\ext\msgpack;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** msgpack_pack() — MessagePack encode (php-src ext/msgpack/msgpack.c; #6551). */
final class msgpack_pack extends Internal
{
    public function __construct()
    {
        parent::__construct('msgpack_pack');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'msgpack_pack', 1);
        if (null === $frame->returnVar) {
            return;
        }
        try {
            $packed = VmMsgpack::pack($frame->calledArgs[0]);
        } catch (\Throwable $e) {
            throw new \Exception($e->getMessage(), 0, $e);
        }
        $frame->returnVar->string($packed);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('msgpack_pack() is not implemented for JIT in this compiler build (issue #6551)');
    }
}
