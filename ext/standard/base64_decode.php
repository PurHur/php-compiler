<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** base64_decode() — non-strict RFC 4648 decode (subset of PHP; native LLVM in JIT/AOT). */
final class base64_decode extends Internal
{
    public function __construct()
    {
        parent::__construct('base64_decode');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('base64_decode() requires exactly one argument in this compiler build');
        }
        $data = $frame->calledArgs[0]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_STRING !== $data->type) {
            throw new \LogicException('base64_decode() only supports strings in this compiler build');
        }
        $result = VmString::base64_decode($data->toString());
        if (false === $result) {
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->string($result);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('base64_decode() requires exactly one argument in this compiler build');
        }
        $str = $this->jitString($context, $args[0], 'base64_decode() argument #1');

        return JitBase64Decode::decode($context, $str);
    }
}
