<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM;
use PHPCompiler\VM\ReflectionSupport;

/** ReflectionMethod::invokeArgs($object, array $args) — VM (#7117, #23388, php_reflection.c). */
final class ReflectionMethodInvokeArgs extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('invokeArgs');
    }

    public function execute(Frame $frame): void
    {
        // php-src: zim_ReflectionMethod_invokeArgs — ZEND_PARSE_PARAMETERS exactly 2 user args (#30922)
        $this->requireExactUserArgCount($frame, 'ReflectionMethod::invokeArgs', 2);
        $reflection = ReflectionSupport::requireReflectionMethod($frame, $frame->calledArgs[0]);
        $objectArg = $frame->calledArgs[1];
        $ctx = VmReflection::requireContext($frame);
        [$paramNames, $variadicIndex, $functionName] = ReflectionSupport::methodInvokeParamMetadata(
            $ctx,
            $reflection
        );
        $invokeArgs = ReflectionSupport::invokeArgsFromArray(
            $frame->calledArgs[2],
            'ReflectionMethod::invokeArgs',
            2,
            $paramNames,
            $variadicIndex,
            $functionName
        );
        $vm = VM::running();
        if (null === $vm) {
            throw new \LogicException('ReflectionMethod::invokeArgs() requires active VM');
        }
        $result = ReflectionSupport::invokeReflectedMethod($vm, $frame, $reflection, $objectArg, $invokeArgs);
        if (null !== $frame->returnVar) {
            $frame->returnVar->copyFrom($result);
        }
    }
}
