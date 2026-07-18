<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sqlite3;

use PHPCompiler\Frame;
use PHPCompiler\ext\standard\VmCallable;
use PHPCompiler\ext\spl\SplIteratorSupport;

/**
 * SQLite3::createAggregate(string $name, callable $stepCallback, callable $finalCallback, int $argCount = -1): bool
 * (php-src ext/sqlite3/sqlite3.c; #20585).
 *
 * Stores step/final callables; SQL expansion evaluates SELECT agg(cols) FROM … (PHP 8.2 path).
 */
final class SQLite3CreateAggregate extends Sqlite3ClassMethod
{
    public function __construct()
    {
        parent::__construct('createAggregate');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'SQLite3::createAggregate()');
        VmSQLite3::requireOpenDb($receiver, 'SQLite3::createAggregate');
        if (\count($frame->calledArgs) < 4) {
            throw new \ArgumentCountError(
                'SQLite3::createAggregate() expects at least 3 arguments, '.(\count($frame->calledArgs) - 1).' given'
            );
        }
        $name = $this->stringArg($frame->calledArgs[1], 'SQLite3::createAggregate', 1, 'name');
        if ('' === $name) {
            if (null !== $frame->returnVar) {
                $frame->returnVar->bool(false);
            }

            return;
        }
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('SQLite3::createAggregate() requires a VM context');
        }
        $step = $frame->calledArgs[2]->resolveIndirect();
        if (!VmCallable::isCallable($ctx, $step)) {
            throw new \TypeError(VmCallable::invalidCallbackTypeError('SQLite3::createAggregate'));
        }
        $final = $frame->calledArgs[3]->resolveIndirect();
        if (!VmCallable::isCallable($ctx, $final)) {
            throw new \TypeError(VmCallable::invalidCallbackTypeError('SQLite3::createAggregate'));
        }
        $argc = -1;
        if (\count($frame->calledArgs) >= 5) {
            $argc = $this->intArg($frame->calledArgs[4], 'SQLite3::createAggregate', 4, 'argCount', -1);
        }
        [$stepPinned, $stepClosure] = SplIteratorSupport::pinCallback($step);
        [$finalPinned, $finalClosure] = SplIteratorSupport::pinCallback($final);
        $state = VmSQLite3::state($receiver);
        $state->aggregates[strtolower($name)] = [
            'step' => $stepPinned,
            'stepClosure' => $stepClosure,
            'final' => $finalPinned,
            'finalClosure' => $finalClosure,
            'argc' => $argc,
            'ctx' => $ctx,
        ];
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(true);
        }
    }
}
