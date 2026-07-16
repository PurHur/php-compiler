<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;

/**
 * Normalizer::getRawDecomposition() — UCD Decomposition_Mapping (#19535).
 *
 * php-src: ext/intl/normalizer/normalizer_normalize.c — normalizer_get_raw_decomposition.
 */
final class NormalizerGetRawDecomposition extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getRawDecomposition');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \ArgumentCountError(
                \sprintf('Normalizer::getRawDecomposition() expects 1 or 2 arguments, %d given', $argc)
            );
        }
        $input = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            'Normalizer::getRawDecomposition',
            0,
            'string'
        );
        $form = 2 === $argc
            ? VmNormalizer::parseFormFromFrame($frame, 1, 'Normalizer::getRawDecomposition', 2)
            : VmNormalizer::FORM_C;
        $result = VmNormalizer::getRawDecomposition($input, $form, 'Normalizer::getRawDecomposition');
        if (null === $frame->returnVar) {
            return;
        }
        if (null === $result) {
            $frame->returnVar->null();
        } else {
            $frame->returnVar->string($result);
        }
    }
}
