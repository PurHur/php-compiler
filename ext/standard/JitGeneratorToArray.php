<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\GeneratorHelper;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/**
 * LLVM JIT/AOT lowering for generator_to_array() (issue #6025, php-src ext/standard/array.c).
 */
final class JitGeneratorToArray
{
    public static function invoke(Context $context, Variable $generator, bool $preserveKeys): Value
    {
        if (!GeneratorHelper::isGeneratorVariable($generator)) {
            throw new \LogicException(
                'generator_to_array() argument must be a Generator in this compiler build'
            );
        }

        return JitIteratorToArray::invoke($context, $generator, $preserveKeys);
    }

    public static function invokeWithPreserveKeysFlag(Context $context, Variable $generator, Value $preserveKeys): Value
    {
        if (!GeneratorHelper::isGeneratorVariable($generator)) {
            throw new \LogicException(
                'generator_to_array() argument must be a Generator in this compiler build'
            );
        }

        return JitIteratorToArray::invokeWithPreserveKeysFlag($context, $generator, $preserveKeys);
    }
}
