<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sqlite3;

use PHPCompiler\Frame;
use PHPCompiler\ext\standard\VmCallable;
use PHPCompiler\ext\spl\SplIteratorSupport;
use PHPCompiler\VM\Variable;

/**
 * SQLite3::setAuthorizer(?callable $callback): bool (php-src ext/sqlite3/sqlite3.c; #20683).
 */
final class SQLite3SetAuthorizer extends Sqlite3ClassMethod
{
    public function __construct()
    {
        parent::__construct('setAuthorizer');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'SQLite3::setAuthorizer()');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError(
                'SQLite3::setAuthorizer() expects exactly 1 argument, '.(\count($frame->calledArgs) - 1).' given'
            );
        }
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('SQLite3::setAuthorizer() requires a VM context');
        }
        $state = VmSQLite3::state($receiver);
        $arg = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_NULL === $arg->type) {
            $state->authorizer = null;
            $state->authorizerClosure = null;
            $state->authorizerCtx = null;
            if (null !== $frame->returnVar) {
                $frame->returnVar->bool(true);
            }

            return;
        }
        if (!VmCallable::isCallable($ctx, $arg)) {
            throw new \TypeError(VmCallable::invalidCallbackTypeError('SQLite3::setAuthorizer'));
        }
        [$pinned, $closureState] = SplIteratorSupport::pinCallback($arg);
        $state->authorizer = $pinned;
        $state->authorizerClosure = $closureState;
        $state->authorizerCtx = $ctx;
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(true);
        }
    }
}
