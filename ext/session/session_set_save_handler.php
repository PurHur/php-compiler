<?php

declare(strict_types=1);

namespace PHPCompiler\ext\session;

use PHPCompiler\ext\standard\VmMath;
use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\ext\standard\VmSession;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * session_set_save_handler() — register userspace session storage (php-src ext/session/session.c; #4873).
 */
final class session_set_save_handler extends Internal
{
    public function __construct()
    {
        parent::__construct('session_set_save_handler');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \ArgumentCountError(
                'session_set_save_handler() expects at least 1 argument, '.$argc.' given'
            );
        }
        if (!VmSession::canChangeSaveHandler($frame)) {
            BuiltinExecute::writeReturn($frame, static function (Variable $ret): void {
                $ret->bool(false);
            });

            return;
        }
        $handler = SessionUserHandler::requireHandlerObject(
            $frame->calledArgs[0],
            'session_set_save_handler'
        );
        $ctx = VmReflection::requireContext($frame);
        SessionUserHandler::assertHandlerMethods($ctx->runtime->vm, $handler, 'session_set_save_handler');
        $registerShutdown = true;
        if (2 === $argc) {
            $registerShutdown = VmMath::parseBoolBuiltinArg(
                $frame->calledArgs[1]->resolveIndirect(),
                'session_set_save_handler',
                2,
                'register_shutdown'
            );
        }
        $ok = SessionUserHandler::install($handler, $registerShutdown);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($ok): void {
            $ret->bool($ok);
        });
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException(
            'session_set_save_handler() is not lowered for JIT/AOT in this compiler build'
        );
    }
}
