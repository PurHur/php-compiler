<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\Func\PHP as PhpFunc;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionFunction::hasReturnType() — VM (#5141, #22068; ext/reflection/php_reflection.c). */
final class ReflectionFunctionHasReturnType extends VmClassMethod
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
        $receiver = ReflectionSupport::requireReflectionFunction($frame, $frame->calledArgs[0]);
        if (ReflectionSupport::isReflectionInternalFunction($receiver)) {
            // php-src: ignores ZEND_TYPE_IS_TENTATIVE; free-function stubs use non-tentative types.
            $frame->returnVar->bool(ReflectionSupport::reflectedFunctionHasInternalReturnType($receiver));

            return;
        }
        $func = ReflectionSupport::resolveFunctionFromReflection($ctx, $receiver);
        $frame->returnVar->bool(
            $func instanceof PhpFunc
            && ReflectionSupport::hasDeclaredReturnType($func->block->returnDeclaredType)
        );
    }
}
