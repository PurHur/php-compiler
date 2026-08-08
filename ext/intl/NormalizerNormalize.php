<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPLLVM\Value;

/**
 * Normalizer::normalize() — OOP alias of normalizer_normalize() (#19535, AOT #28654).
 *
 * Z_PARAM_STR null TypeError on 8.4 forward profile (#21063, normalizer.stub.php).
 */
final class NormalizerNormalize extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('normalize');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \ArgumentCountError(
                \sprintf('Normalizer::normalize() expects 1 or 2 arguments, %d given', $argc)
            );
        }
        // Z_PARAM_STR — null TypeError on 8.4 forward profile (#21063).
        $input = VmString::zparamStrBuiltinArgForFrame(
            $frame,
            0,
            'Normalizer::normalize',
            0,
            'string'
        );
        $form = 2 === $argc
            ? VmNormalizer::parseFormFromFrame($frame, 1, 'Normalizer::normalize', 2)
            : VmNormalizer::FORM_C;
        if (null !== $frame->returnVar) {
            $frame->returnVar->string(VmNormalizer::normalize($input, $form));
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitNormalizerNormalize::invokeMethod($context, ...$args);
    }
}
