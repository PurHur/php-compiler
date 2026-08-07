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

/**
 * msgpack_unserialize() — alias of msgpack_unpack (PECL msgpack/msgpack-php msgpack.c; #27872).
 *
 * ZEND_FALIAS(msgpack_unpack, msgpack_unserialize, …) — same decode path as msgpack_unpack.
 */
final class msgpack_unserialize extends Internal
{
    public function __construct()
    {
        parent::__construct('msgpack_unserialize');
    }

    public function execute(Frame $frame): void
    {
        $this->requireAtLeastArgCount($frame, 'msgpack_unserialize', 1);
        $this->requireAtMostArgCount($frame, 'msgpack_unserialize', 2);
        if (null === $frame->returnVar) {
            return;
        }
        $data = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            'msgpack_unserialize',
            0,
            'str'
        );
        $offset = 0;
        if (isset($frame->calledArgs[1])) {
            $offset = VmMath::parseIntBuiltinArgForFrame(
                $frame,
                1,
                'msgpack_unserialize',
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
        throw new \Error('msgpack_unserialize() is not implemented for JIT in this compiler build (issue #27872)');
    }
}
