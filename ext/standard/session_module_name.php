<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\ErrorReporter;
use PHPLLVM\Value;

/**
 * session_module_name() — get/set session save-handler module (php-src ext/session/session.c; #5749).
 *
 * Excess argc → Zend ArgumentCountError (#30684; php-src ext/session/session.c).
 */
class session_module_name extends Internal
{
    public function __construct()
    {
        parent::__construct('session_module_name');
    }

    public function execute(Frame $frame): void
    {
        // php-src stub arity: 0..1 (#30684; ext/session/session.stub.php).
        $this->requireAtMostArgCount($frame, 'session_module_name', 1);
        $argc = \count($frame->calledArgs);
        $setModule = null;
        if (1 === $argc) {
            $setModule = VmString::coerceNullableStringBuiltinArg(
                $frame->calledArgs[0]->resolveIndirect(),
                'session_module_name',
                0,
                'module'
            );
        }
        if (null !== $setModule && !VmSession::canChangeSaveHandler($frame)) {
            if (null !== $frame->returnVar) {
                $frame->returnVar->bool(false);
            }

            return;
        }
        $current = VmSession::getModuleName();
        if (null !== $setModule) {
            if (0 === strcasecmp($setModule, 'user')) {
                throw new \ValueError('session_module_name(): Argument #1 ($module) must not be "user"');
            }
            if (!VmSession::isSupportedModule($setModule)) {
                $this->triggerWarning(
                    $frame,
                    \sprintf('Session handler module "%s" cannot be found', $setModule)
                );
                if (null !== $frame->returnVar) {
                    $frame->returnVar->bool(false);
                }

                return;
            }
            VmSession::setModuleName($setModule);
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->string($current);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        // Catchable ArgumentCountError under AOT try/catch (#30684).
        if (!$this->requireAtMostJitArgCount($context, $args, 'session_module_name', 1)) {
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }

        return \call_user_func_array([JitSessionModuleName::class, 'invoke'], array_merge([$context], $args));
    }

    private function triggerWarning(Frame $frame, string $message): void
    {
        if (null === $frame->vmContext) {
            return;
        }
        $frame->vmContext->errors->triggerError(
            $message,
            ErrorReporter::E_WARNING,
            '' !== $frame->scriptPath ? $frame->scriptPath : null,
            $frame->vmContext,
            $frame
        );
    }
}
