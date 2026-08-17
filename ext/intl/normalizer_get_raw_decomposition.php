<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * normalizer_get_raw_decomposition() — UCD Decomposition_Mapping (#19535).
 *
 * php-src: ext/intl/normalizer — alias of Normalizer::getRawDecomposition().
 * Reflection stub: string $string, int $form = FORM_C → ?string (#27705; normalizer.stub.php via
 * {@see \PHPCompiler\BuiltinInternalArgInfo} / {@see \PHPCompiler\BuiltinParamNames}).
 */
final class normalizer_get_raw_decomposition extends Internal
{
    public function __construct()
    {
        parent::__construct('normalizer_get_raw_decomposition');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \ArgumentCountError(
                \sprintf('normalizer_get_raw_decomposition() expects 1 or 2 arguments, %d given', $argc)
            );
        }
        $input = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            'normalizer_get_raw_decomposition',
            0,
            'string'
        );
        $form = 2 === $argc
            ? VmNormalizer::parseFormFromFrame($frame, 1, 'normalizer_get_raw_decomposition', 2)
            : VmNormalizer::FORM_C;
        $result = VmNormalizer::getRawDecomposition($input, $form, 'normalizer_get_raw_decomposition');
        if (null === $frame->returnVar) {
            return;
        }
        if (null === $result) {
            $frame->returnVar->null();
        } else {
            $frame->returnVar->string($result);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException(
            'normalizer_get_raw_decomposition() JIT runtime lowering is deferred; use VM (#19535)'
        );
    }
}
