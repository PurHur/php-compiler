<?php

declare(strict_types=1);

namespace PHPCompiler\ext\msgpack;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * msgpack_serialize() — alias of msgpack_pack (PECL msgpack/msgpack-php msgpack.c; #27872).
 *
 * ZEND_FALIAS(msgpack_pack, msgpack_serialize, …) — both names share the same encode path.
 */
final class msgpack_serialize extends Internal
{
    public function __construct()
    {
        parent::__construct('msgpack_serialize');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'msgpack_serialize', 1);
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
        throw new \Error('msgpack_serialize() is not implemented for JIT in this compiler build (issue #27872)');
    }
}
