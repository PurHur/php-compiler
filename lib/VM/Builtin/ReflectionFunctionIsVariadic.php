<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;

/**
 * ReflectionFunction::isVariadic() — VM (#22045, ext/reflection/php_reflection.c).
 *
 * Sibling of {@see ReflectionMethodIsVariadic} (#18228); php-src shares
 * zim_reflection_function_abstract_isVariadic for Function/Method.
 */
final class ReflectionFunctionIsVariadic extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('isVariadic');
    }

    public function execute(Frame $frame): void
    {
        // php-src: zim_ReflectionFunctionAbstract_isVariadic — 0 args (#30924)
        VmReflection::requireFunctionAbstractReceiverOnlyArgc($frame, 'isVariadic');
        $receiver = ReflectionSupport::requireReflectionFunction($frame, $frame->calledArgs[0]);
        $ctx = VmReflection::requireContext($frame);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(ReflectionSupport::isReflectionFunctionVariadic($ctx, $receiver));
        }
    }
}
