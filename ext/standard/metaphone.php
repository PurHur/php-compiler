<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\StringMetaphone;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * metaphone() — phonetic encoding (subset of PHP; issue #2423).
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
        $arg = $frame->calledArgs[0]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_STRING !== $arg->type) {
            throw new \LogicException('metaphone() only supports strings in this compiler build');
        }
        $max = 0;
        if (2 === $argc) {
            $maxVar = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $maxVar->type) {
                throw new \LogicException('metaphone() max phonemes must be an integer in this compiler build');
            }
            $max = $maxVar->toInt();
            if ($max < 0) {
                throw new \LogicException('metaphone(): Argument #2 ($max_phonemes) must be greater than or equal to 0');
            }
        }
        $frame->returnVar->string(VmString::metaphone($arg->toString(), $max));
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        StringMetaphone::ensureLinked($context);
        $argc = \count($args);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('metaphone() accepts one or two arguments in this compiler build');
        }
        $i64 = $context->getTypeFromString('int64');
        $max = $i64->constInt(0, false);
        if ($argc >= 2) {
            if (JITVariable::TYPE_NATIVE_LONG !== $args[1]->type) {
                throw new \LogicException('metaphone() max phonemes must be an integer in this compiler build');
            }
            $max = $this->jitLong($context, $args[1], 'metaphone() max phonemes');
        }
        $ptr = $this->stringDataPtr(
            $context,
            $this->jitString($context, $args[0], 'metaphone() argument #1')
        );
        $fn = $context->lookupFunction('phpc_metaphone');

        return $context->builder->call($fn, $ptr, $max);
    }
}
