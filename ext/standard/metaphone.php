<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\StringMetaphone;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\InternalStrictArg;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * metaphone() — phonetic string encoding (subset of PHP; issue #2423).
 *
 * VM: {@see VmString::metaphone()}; JIT/AOT: {@see StringMetaphone} + {@see MetaphoneJitHelper}.
 */
final class metaphone extends Internal
{
    public function __construct()
    {
        parent::__construct('metaphone');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('metaphone() accepts one or two arguments in this compiler build');
        }
        $string = InternalStrictArg::resolveCoercibleStringArg($frame, 0, 'metaphone', 'string');
        $maxPhonemes = 0;
        if ($argc >= 2) {
            $maxVar = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $maxVar->type) {
                throw new \LogicException('metaphone() max phonemes must be an integer in this compiler build');
            }
            $maxPhonemes = $maxVar->toInt();
            if ($maxPhonemes < 0) {
                throw new \LogicException('metaphone() max phonemes must be >= 0 in this compiler build');
            }
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->string(VmString::metaphone($string, $maxPhonemes));
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        $argc = \count($args);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('metaphone() accepts one or two arguments in this compiler build');
        }
        $i64 = $context->getTypeFromString('int64');
        $maxPhonemes = $i64->constInt(0, false);
        if ($argc >= 2) {
            if (JITVariable::TYPE_NATIVE_LONG !== $args[1]->type) {
                throw new \LogicException('metaphone() max phonemes must be an integer in this compiler build');
            }
            $maxPhonemes = $this->jitLong($context, $args[1], 'metaphone() max phonemes');
        }

        StringMetaphone::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction('__compiler_metaphone'),
            JitStringBuiltinArg::lowerStrictOrCoercible($context, $args[0], 'metaphone', 0, 'string'),
            $maxPhonemes
        );
    }
}
