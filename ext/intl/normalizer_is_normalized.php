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
 * normalizer_is_normalized() — Unicode normalization probe (php-src ext/intl/normalizer; #5153).
 */
final class normalizer_is_normalized extends Internal
{
    public function __construct()
    {
        parent::__construct('normalizer_is_normalized');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \ArgumentCountError(
                \sprintf('normalizer_is_normalized() expects 1 or 2 arguments, %d given', $argc)
            );
        }
        $input = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            'normalizer_is_normalized',
            0,
            'string'
        );
        $form = 2 === $argc
            ? VmNormalizer::parseFormFromFrame($frame, 1, 'normalizer_is_normalized', 2)
            : VmNormalizer::FORM_C;
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(VmNormalizer::isNormalized($input, $form));
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException(
            'normalizer_is_normalized() JIT runtime lowering is deferred; use VM (#5153)'
        );
    }
}
