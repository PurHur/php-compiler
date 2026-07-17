<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sqlite3;

use PHPCompiler\Frame;
use PHPCompiler\ext\standard\VmCallable;
use PHPCompiler\ext\spl\SplIteratorSupport;

/**
 * SQLite3::createFunction(string $name, callable $callback, int $argCount = -1): bool (#19862).
 *
 * Stores the callable for SQL expansion (PHP 8.2) / future FFI::callback registration.
 */
final class SQLite3CreateFunction extends Sqlite3ClassMethod
{
    public function __construct()
    {
        parent::__construct('createFunction');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'SQLite3::createFunction()');
        if (\count($frame->calledArgs) < 3) {
            throw new \ArgumentCountError(
                'SQLite3::createFunction() expects at least 2 arguments, '.(\count($frame->calledArgs) - 1).' given'
            );
        }
        $name = $this->stringArg($frame->calledArgs[1], 'SQLite3::createFunction', 0, 'name');
        if ('' === $name) {
            if (null !== $frame->returnVar) {
                $frame->returnVar->bool(false);
            }

            return;
        }
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('SQLite3::createFunction() requires a VM context');
        }
        $callback = $frame->calledArgs[2]->resolveIndirect();
        if (!VmCallable::isCallable($ctx, $callback)) {
            throw new \TypeError(VmCallable::invalidCallbackTypeError('SQLite3::createFunction'));
        }
        $argc = -1;
        if (\count($frame->calledArgs) >= 4) {
            $argc = $this->intArg($frame->calledArgs[3], 'SQLite3::createFunction', 2, 'argCount', -1);
        }
        [$pinned, $closureState] = SplIteratorSupport::pinCallback($callback);
        $state = VmSQLite3::state($receiver);
        $state->functions[strtolower($name)] = [
            'callback' => $pinned,
            'closure' => $closureState,
            'argc' => $argc,
            'ctx' => $ctx,
        ];
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(true);
        }
    }
}
