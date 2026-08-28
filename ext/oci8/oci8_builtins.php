<?php

declare(strict_types=1);

namespace PHPCompiler\ext\oci8;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\ext\standard\VmString;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

require_once __DIR__.'/VmOci8Core.php';

/** Shared JIT stub for oci_* v1 (#6441). */
abstract class Oci8Function extends Internal
{
    public function call(Context $context, JITVariable ...$args): Value
    {
        unset($context, $args);
        throw new \LogicException($this->getName().'() is not implemented for JIT in this compiler build (issue #6441)');
    }
}

/** oci_connect() — php-src ext/oci8/oci8_interface.c (#6441). */
final class oci_connect extends Oci8Function
{
    public function __construct()
    {
        parent::__construct('oci_connect');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 5) {
            throw new \ArgumentCountError(\sprintf(
                'oci_connect() expects between 1 and 5 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'oci_connect', 1, 'username');
        if ($argc >= 2) {
            VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'oci_connect', 2, 'password');
        }
        if ($argc >= 3 && Variable::TYPE_NULL !== $frame->calledArgs[2]->resolveIndirect()->type) {
            VmString::coerceStringBuiltinArg($frame->calledArgs[2], 'oci_connect', 3, 'connection_string');
        }
        VmOci8Core::requireNativeDriver('oci_connect');
        $frame->returnVar->bool(false);
    }
}

/** oci_parse() — php-src ext/oci8/oci8_statement.c (#6441). */
final class oci_parse extends Oci8Function
{
    public function __construct()
    {
        parent::__construct('oci_parse');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'oci_parse() expects exactly 2 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        self::requireConnection($frame->calledArgs[0], 'oci_parse', 1);
        VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'oci_parse', 2, 'sql');
        VmOci8Core::requireNativeDriver('oci_parse');
        $frame->returnVar->bool(false);
    }

    private static function requireConnection(Variable $var, string $fn, int $argNum): void
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $var->type && Variable::TYPE_RESOURCE !== $var->type) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d must be a valid OCI8 connection resource, %s given',
                $fn,
                $argNum,
                match ($var->type) {
                    Variable::TYPE_NULL => 'null',
                    Variable::TYPE_STRING => 'string',
                    Variable::TYPE_INTEGER => 'int',
                    default => 'mixed',
                }
            ));
        }
    }
}

/** oci_execute() — php-src ext/oci8/oci8_statement.c (#6441). */
final class oci_execute extends Oci8Function
{
    public function __construct()
    {
        parent::__construct('oci_execute');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \ArgumentCountError(\sprintf(
                'oci_execute() expects between 1 and 2 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        self::requireStatement($frame->calledArgs[0], 'oci_execute', 1);
        VmOci8Core::requireNativeDriver('oci_execute');
        $frame->returnVar->bool(false);
    }

    private static function requireStatement(Variable $var, string $fn, int $argNum): void
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $var->type && Variable::TYPE_RESOURCE !== $var->type) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d must be a valid OCI8 statement resource, %s given',
                $fn,
                $argNum,
                match ($var->type) {
                    Variable::TYPE_NULL => 'null',
                    Variable::TYPE_STRING => 'string',
                    Variable::TYPE_INTEGER => 'int',
                    default => 'mixed',
                }
            ));
        }
    }
}

/** oci_fetch_array() — php-src ext/oci8/oci8_statement.c (#6441). */
final class oci_fetch_array extends Oci8Function
{
    public function __construct()
    {
        parent::__construct('oci_fetch_array');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \ArgumentCountError(\sprintf(
                'oci_fetch_array() expects between 1 and 2 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        self::requireStatement($frame->calledArgs[0], 'oci_fetch_array', 1);
        VmOci8Core::requireNativeDriver('oci_fetch_array');
        $frame->returnVar->bool(false);
    }

    private static function requireStatement(Variable $var, string $fn, int $argNum): void
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $var->type && Variable::TYPE_RESOURCE !== $var->type) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d must be a valid OCI8 statement resource, %s given',
                $fn,
                $argNum,
                match ($var->type) {
                    Variable::TYPE_NULL => 'null',
                    Variable::TYPE_STRING => 'string',
                    Variable::TYPE_INTEGER => 'int',
                    default => 'mixed',
                }
            ));
        }
    }
}
