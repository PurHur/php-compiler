<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitBoolArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * get_browser() — browscap user-agent lookup (ext/standard/browscap.c, #11172).
 *
 * php-src: ext/standard/browscap.c — php_get_browser
 */
final class get_browser extends Internal
{
    public function __construct()
    {
        parent::__construct('get_browser');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 2) {
            throw new \ArgumentCountError(\sprintf(
                'get_browser() expects at most 2 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar || null === $frame->vmContext) {
            return;
        }

        if ($argc >= 1) {
            $uaArg = $frame->calledArgs[0]->resolveIndirect();
            if (Variable::TYPE_NULL !== $uaArg->type) {
                VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'get_browser', 0, 'browser_name');
            }
        }
        if ($argc >= 2) {
            VmMath::parseBoolBuiltinArg($frame->calledArgs[1], 'get_browser', 1, 'return_array');
        }

        if (!VmBrowser::browscapConfigured($frame->vmContext)) {
            VmBrowser::triggerBrowscapNotSetWarning(
                $frame->vmContext,
                '' !== $frame->scriptPath ? $frame->scriptPath : null,
                $frame
            );
            BuiltinExecute::writeReturn($frame, static function (Variable $ret): void {
                $ret->bool(false);
            });

            return;
        }

        // Browscap database parsing deferred — return false until reader lands.
        BuiltinExecute::writeReturn($frame, static function (Variable $ret): void {
            $ret->bool(false);
        });
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc > 2) {
            throw new \LogicException('get_browser() expects at most 2 arguments in this compiler build');
        }
        if ($argc >= 1 && JITVariable::TYPE_NULL !== $args[0]->type) {
            JitStringBuiltinArg::lower($context, $args[0], 'get_browser', 0, 'browser_name');
        }
        if ($argc >= 2) {
            JitBoolArg::lower($context, $args[1], 'get_browser(): Argument #2 ($return_array)');
        }

        return JitGetBrowser::invoke($context);
    }
}
