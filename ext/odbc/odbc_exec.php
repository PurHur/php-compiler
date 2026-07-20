<?php

declare(strict_types=1);

namespace PHPCompiler\ext\odbc;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** odbc_exec() / odbc_do() — php-src ext/odbc/php_odbc.c (#6293 / #21308). */
final class odbc_exec extends Internal
{
    public function __construct(string $name = 'odbc_exec')
    {
        parent::__construct($name);
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \ArgumentCountError(\sprintf(
                '%s() expects between 2 and 3 arguments, %d given',
                $this->name,
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $conn = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $conn->type
            || !VmOdbcConnection::isLive($conn->toObject())
        ) {
            throw new \TypeError(\sprintf(
                '%s(): supplied resource is not a valid ODBC connection resource',
                $this->name
            ));
        }
        $query = VmString::coerceStringBuiltinArg($frame->calledArgs[1], $this->name, 1, 'query');
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException($this->name.'() requires a VM context');
        }
        $result = VmOdbcCore::exec($conn->toObject(), $query, $ctx, $frame);
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->copyFrom($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException(
            $this->name.'() is not implemented for JIT in this compiler build (issue #6293/#21308)'
        );
    }
}
