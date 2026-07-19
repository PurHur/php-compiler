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
 * session_set_save_handler() — register userspace session storage (php-src ext/session/session.c; #4873, #21136).
 *
 * Overloads: 1–2 args object/{@see SessionHandlerInterface}; 6–9 args callable open…gc[+create_sid…].
 */
final class session_set_save_handler extends Internal
{
    private const MAX_CALLBACK_ARGS = 9;

    public function __construct()
    {
        parent::__construct('session_set_save_handler');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (0 === $argc || ($argc >= 3 && $argc < 6) || $argc > self::MAX_CALLBACK_ARGS) {
            throw new \ArgumentCountError(
                'Wrong parameter count for session_set_save_handler()'
            );
        }
        if ($argc <= 2) {
            $this->executeObjectForm($frame, $argc);

            return;
        }
        $this->executeCallableForm($frame, $argc);
    }

    private function executeObjectForm(Frame $frame, int $argc): void
    {
        $ctx = VmReflection::requireContext($frame);
        $handler = SessionUserHandler::requireHandlerObject(
            $frame->calledArgs[0],
            'session_set_save_handler'
        );
        SessionUserHandler::assertSessionHandlerInterface($ctx, $handler, 'session_set_save_handler');
        SessionUserHandler::assertHandlerMethods($ctx->runtime->vm, $handler, 'session_set_save_handler');
        if (!VmSession::canChangeSaveHandler($frame)) {
            BuiltinExecute::writeReturn($frame, static function (Variable $ret): void {
                $ret->bool(false);
            });

            return;
        }
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

    private function executeCallableForm(Frame $frame, int $argc): void
    {
        $ctx = VmReflection::requireContext($frame);
        $args = [];
        for ($i = 0; $i < $argc; ++$i) {
            $args[] = $frame->calledArgs[$i];
        }
        // php-src: zend_is_callable for each arg before save_handler_check_session (#21136).
        $callbacks = SessionUserHandler::requireCallableArgs($ctx, $args);
        if (!VmSession::canChangeSaveHandler($frame)) {
            BuiltinExecute::writeReturn($frame, static function (Variable $ret): void {
                $ret->bool(false);
            });

            return;
        }
        $ok = SessionUserHandler::installCallables($callbacks);
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
