<?php

declare(strict_types=1);

namespace PHPCompiler\ext\msgpack;

use PHPCompiler\ext\standard\VmJson;
use PHPCompiler\ext\standard\VmMath;
use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** msgpack_unpack() — MessagePack decode (php-src ext/msgpack/msgpack.c; #6551). */
final class msgpack_unpack extends Internal
{
    public function __construct()
    {
        parent::__construct('msgpack_unpack');
    }

    public function execute(Frame $frame): void
    {
        $this->requireAtLeastArgCount($frame, 'msgpack_unpack', 1);
        $this->requireAtMostArgCount($frame, 'msgpack_unpack', 2);
        if (null === $frame->returnVar) {
            return;
        }
        $data = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            'msgpack_unpack',
            0,
            'data'
        );
        $offset = 0;
        if (isset($frame->calledArgs[1])) {
            $offset = VmMath::parseIntBuiltinArgForFrame(
                $frame,
                1,
                'msgpack_unpack',
                2,
                'offset'
            );
        }
        $decoded = VmMsgpack::unpack($data, $offset, $frame);
        if (false === $decoded) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->copyFrom(VmJson::import($decoded));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('msgpack_unpack() is not implemented for JIT in this compiler build (issue #6551)');
    }
}
