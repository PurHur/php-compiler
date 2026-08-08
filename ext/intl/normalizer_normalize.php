<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\VM\InternalStrictArg;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * normalizer_normalize() — Unicode normalization (php-src ext/intl/normalizer; #5153, AOT #28654).
 *
 * Z_PARAM_STR null TypeError on 8.4 forward profile (#21063, normalizer.stub.php).
 */
final class normalizer_normalize extends Internal
{
    public function __construct()
    {
        parent::__construct('normalizer_normalize');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \ArgumentCountError(
                \sprintf('normalizer_normalize() expects 1 or 2 arguments, %d given', $argc)
            );
        }
        // Z_PARAM_STR — Zend 8.4 DEP+coerce on null, not TypeError (#21320, normalizer.stub.php).
        if (InternalStrictArg::isCallerStrict($frame)) {
            InternalStrictArg::requireString($frame, 0, 'normalizer_normalize', 'string');
            $input = $frame->calledArgs[0]->resolveIndirect()->toString();
        } else {
            $input = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'normalizer_normalize', 0, 'string', 'string', false);
        }
        $form = 2 === $argc
            ? VmNormalizer::parseFormFromFrame($frame, 1, 'normalizer_normalize', 2)
            : VmNormalizer::FORM_C;
        if (null !== $frame->returnVar) {
            $frame->returnVar->string(VmNormalizer::normalize($input, $form));
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitNormalizerNormalize::invokeProcedural($context, ...$args);
    }
}
