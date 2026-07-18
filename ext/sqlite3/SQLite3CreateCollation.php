<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sqlite3;

use PHPCompiler\Frame;
use PHPCompiler\ext\standard\VmCallable;
use PHPCompiler\ext\spl\SplIteratorSupport;

/**
 * SQLite3::createCollation(string $name, callable $callback): bool
 * (php-src ext/sqlite3/sqlite3.c; #20565).
 *
 * Stores the callable for registration parity; full COLLATE FFI callback is follow-up.
 */
final class SQLite3CreateCollation extends Sqlite3ClassMethod
{
    public function __construct()
    {
        parent::__construct('createCollation');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'SQLite3::createCollation()');
        VmSQLite3::requireOpenDb($receiver, 'SQLite3::createCollation');
        if (\count($frame->calledArgs) < 3) {
            throw new \ArgumentCountError(
                'SQLite3::createCollation() expects exactly 2 arguments, '.(\count($frame->calledArgs) - 1).' given'
            );
        }
        $name = $this->stringArg($frame->calledArgs[1], 'SQLite3::createCollation', 0, 'name');
        if ('' === $name) {
            if (null !== $frame->returnVar) {
                $frame->returnVar->bool(false);
            }

            return;
        }
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('SQLite3::createCollation() requires a VM context');
        }
        $callback = $frame->calledArgs[2]->resolveIndirect();
        if (!VmCallable::isCallable($ctx, $callback)) {
            throw new \TypeError(VmCallable::invalidCallbackTypeError('SQLite3::createCollation'));
        }
        [$pinned, $closureState] = SplIteratorSupport::pinCallback($callback);
        $state = VmSQLite3::state($receiver);
        $state->collations[strtolower($name)] = [
            'callback' => $pinned,
            'closure' => $closureState,
            'ctx' => $ctx,
        ];
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(true);
        }
    }
}
