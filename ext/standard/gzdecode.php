<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** gzdecode() — decode gzip-encoded string (ext/zlib/zlib.c parity, issue #3194). */
final class gzdecode extends Internal
{
    public function __construct()
    {
        parent::__construct('gzdecode');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('gzdecode() expects one or two arguments in this compiler build');
        }
        $data = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'gzdecode', 0, 'data');
        $maxLength = 0;
        if (2 === $argc) {
            $maxVar = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $maxVar->type) {
                throw new \LogicException('gzdecode() max_length must be an integer in this compiler build');
            }
            $maxLength = $maxVar->toInt();
        }
        if (null === $frame->returnVar) {
            return;
        }
        $result = VmZlib::gzdecode($data, $maxLength);
        if (false === $result) {
            VmZlib::triggerWarning($frame, 'gzdecode(): data error');

            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('gzdecode() is not implemented for JIT in this compiler build (issue #3194)');
    }
}
