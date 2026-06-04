<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
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
        $data = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'base64_decode', 0, 'string');
        $result = VmString::base64_decode($data);
        BuiltinExecute::writeReturn($frame, static function ($ret) use ($result): void {
            if (false === $result) {
                $ret->bool(false);
            } else {
                $ret->string($result);
            }
        });
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('base64_decode() requires exactly one argument in this compiler build');
        }
        return JitBase64Decode::decode(
            $context,
            JitStringBuiltinArg::lower($context, $args[0], 'base64_decode', 0, 'string')
        );
    }
}
