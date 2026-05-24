<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\PregReplaceCallbackPolicy;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * preg_replace_callback() — VM with string user-function callbacks (issue #1177).
 *
 * JIT/AOT: deferred — callbacks require user-function / callable JIT ([#142](https://github.com/PurHur/php-compiler/issues/142)).
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
        throw new \LogicException(
            'preg_replace_callback() not implemented for JIT in this compiler build (issue #1177); '
            .PregReplaceCallbackPolicy::DEFERRED_KINDS.' are deferred (#142)'
        );
    }
}
