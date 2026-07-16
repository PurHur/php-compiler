<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;

/** Normalizer::isNormalized() — OOP alias of normalizer_is_normalized() (#19535). */
final class NormalizerIsNormalized extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('isNormalized');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \ArgumentCountError(
                \sprintf('Normalizer::isNormalized() expects 1 or 2 arguments, %d given', $argc)
            );
        }
        $input = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            'Normalizer::isNormalized',
            0,
            'string'
        );
        $form = 2 === $argc
            ? VmNormalizer::parseFormFromFrame($frame, 1, 'Normalizer::isNormalized', 2)
            : VmNormalizer::FORM_C;
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(VmNormalizer::isNormalized($input, $form));
        }
    }
}
