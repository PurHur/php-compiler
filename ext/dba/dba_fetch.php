<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dba;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/**
 * dba_fetch() — php-src ext/dba/dba.c (#4422).
 *
 * Phase 1: standard signature dba_fetch(string|array $key, Dba\Connection $dba, int $skip = 0).
 * Legacy flipped (key, skip, dba) is a follow-up.
 */
final class dba_fetch extends DbaFunction
{
    public function __construct()
    {
        parent::__construct('dba_fetch');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \ArgumentCountError(\sprintf(
                'dba_fetch() expects between 2 and 3 arguments, %d given',
                $argc
            ));
        }
        $key = VmDbaCore::coerceKey($frame->calledArgs[0], 'dba_fetch', 0);
        $conn = VmDbaCore::requireConnection($frame->calledArgs[1], 'dba_fetch');
        // $skip ignored for flatfile (php-src only special-cases cdb/inifile)
        $value = VmDbaCore::fetch($conn, $key);
        BuiltinExecute::writeReturn(
            $frame,
            static function (Variable $ret) use ($value): void {
                if (false === $value) {
                    $ret->bool(false);

                    return;
                }
                $ret->string($value);
            }
        );
    }
}
