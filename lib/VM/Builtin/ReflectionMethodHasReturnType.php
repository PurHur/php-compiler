<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\Func\PHP as PhpFunc;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionMethod::hasReturnType() — VM (#5141, #25406; ext/reflection/php_reflection.c). */
final class ReflectionMethodHasReturnType extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('hasReturnType');
    }

    public function execute(Frame $frame): void
    {
        // php-src: zim_ReflectionFunctionAbstract_hasReturnType — 0 args (#30924)
        VmReflection::requireFunctionAbstractReceiverOnlyArgc($frame, 'hasReturnType');
        if (null === $frame->returnVar) {
            return;
        }
        $ctx = VmReflection::requireContext($frame);
        $receiver = ReflectionSupport::requireReflectionMethod($frame, $frame->calledArgs[0]);
        [$declaring, $methodLc] = ReflectionSupport::resolveReflectedMethodDeclaring($ctx, $receiver);
        $func = $declaring->methods[$methodLc] ?? null;
        if ($func instanceof PhpFunc) {
            $frame->returnVar->bool(
                ReflectionSupport::hasDeclaredReturnType($func->block->returnDeclaredType)
            );

            return;
        }
        if (isset($declaring->methodReturnDeclaredTypes[$methodLc])) {
            $frame->returnVar->bool(
                ReflectionSupport::hasDeclaredReturnType($declaring->methodReturnDeclaredTypes[$methodLc])
            );

            return;
        }
        $frame->returnVar->bool(false);
    }
}
