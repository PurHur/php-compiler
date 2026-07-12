<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\StringBase64Encode;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPLLVM\Value;

/** base64_encode() — RFC 4648 standard alphabet (subset of PHP). */
final class base64_encode extends Internal
{
    public function __construct()
    {
        parent::__construct('base64_encode');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('base64_encode() requires exactly one argument in this compiler build');
        }
        $data = VmString::stringBuiltinArgForFrame($frame, 0, 'base64_encode', 0, 'string');
        BuiltinExecute::writeReturn($frame, static function ($ret) use ($data): void {
            $ret->string(VmString::base64_encode($data));
        });
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('base64_encode() requires exactly one argument in this compiler build');
        }
        $literal = $args[0]->compileTimeString ?? JitStringArg::compileTimeLiteral($args[0]);
        if (null !== $literal) {
            return $context->builder->load(
                $context->constantStringFromString(VmString::base64_encode($literal))
            );
        }

        StringBase64Encode::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction('__compiler_base64_encode'),
            JitStringBuiltinArg::lowerStrictOrCoercible($context, $args[0], 'base64_encode', 0, 'string')
        );
    }
}
