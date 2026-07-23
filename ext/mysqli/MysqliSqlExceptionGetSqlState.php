<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mysqli;

use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\ExceptionSupport;

/**
 * mysqli_sql_exception::getSqlState() — php-src ext/mysqli/mysqli_exception.c (#22456).
 */
final class MysqliSqlExceptionGetSqlState extends VmClassMethod
{
    public const PROP_SQLSTATE = 'sqlstate';

    public function __construct()
    {
        parent::__construct('getSqlState');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException('getSqlState() called without $this');
        }
        $receiver = ExceptionSupport::requireThrowableObject(
            $frame->calledArgs[0],
            'getSqlState()',
            $frame->vmContext
        );
        if (null === $frame->returnVar) {
            return;
        }
        if ($receiver->hasProperty(self::PROP_SQLSTATE)) {
            $frame->returnVar->copyFrom($receiver->getProperty(self::PROP_SQLSTATE));

            return;
        }
        $frame->returnVar->string('00000');
    }
}
