<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\PregReplaceCallbackPolicy;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * preg_replace_callback() — VM with string user-function callbacks (issue #1177).
 *
 * JIT/AOT: compile-time string user-function names in this compile unit (#1177).
 */
final class preg_replace_callback extends Internal
{
    public function __construct()
    {
        parent::__construct('preg_replace_callback');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (3 !== $argc) {
            throw new \LogicException(
                'preg_replace_callback() requires exactly three arguments in this compiler build'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('preg_replace_callback() requires VM context in this compiler build');
        }
        $pattern = VmReflection::stringArg($frame->calledArgs[0], 'preg_replace_callback() pattern');
        $callbackVar = $frame->calledArgs[1]->resolveIndirect();
        if (!PregReplaceCallbackPolicy::isVmSupportedType($callbackVar->type)) {
            throw new \LogicException(PregReplaceCallbackPolicy::vmRejectionMessage());
        }
        $subject = VmReflection::stringArg($frame->calledArgs[2], 'preg_replace_callback() subject');
        $fn = VmUserCall::resolveStringCallback($frame->vmContext, $callbackVar->toString());
        $result = VmPregReplaceCallback::invoke($frame->vmContext, $pattern, $fn, $subject);
        if (false === $result) {
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->string($result);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (3 !== \count($args)) {
            throw new \LogicException(
                'preg_replace_callback() requires exactly three arguments in this compiler build'
            );
        }
        if (JITVariable::TYPE_STRING === $args[0]->type || JITVariable::TYPE_VALUE === $args[0]->type) {
            $this->jitString($context, $args[0], 'preg_replace_callback() pattern');
        }
        if (JITVariable::TYPE_STRING === $args[1]->type || JITVariable::TYPE_VALUE === $args[1]->type) {
            $this->jitString($context, $args[1], 'preg_replace_callback() callback');
        }
        if (JITVariable::TYPE_STRING === $args[2]->type || JITVariable::TYPE_VALUE === $args[2]->type) {
            $this->jitString($context, $args[2], 'preg_replace_callback() subject');
        }

        throw new \LogicException(
            'preg_replace_callback() not implemented for JIT in this compiler build (issue #1177); '
            .PregReplaceCallbackPolicy::DEFERRED_KINDS.' are deferred (#142)'
        );
    }
}
