<?php

declare(strict_types=1);

namespace PHPCompiler\ext\pgsql;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\ext\standard\VmString;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * pg_fetch_array / pg_fetch_object / pg_fetch_result / pg_free_result / pg_result_seek
 * (php-src ext/pgsql/pgsql.c; #20704).
 */

final class pg_fetch_array extends Internal
{
    public function __construct()
    {
        parent::__construct('pg_fetch_array');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 3) {
            throw new \ArgumentCountError(\sprintf(
                'pg_fetch_array() expects between 1 and 3 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $result = VmPgsqlArg::requireResult($frame->calledArgs[0], 'pg_fetch_array', 1);
        $row = null;
        if ($argc >= 2) {
            $rowArg = $frame->calledArgs[1]->resolveIndirect();
            $row = Variable::TYPE_NULL === $rowArg->type ? null : $rowArg->toInt();
        }
        $mode = PgsqlConstants::PGSQL_BOTH;
        if ($argc >= 3) {
            $mode = $frame->calledArgs[2]->resolveIndirect()->toInt();
        }
        $ht = VmPgsqlCore::fetchArray($result, $row, $mode);
        if (false === $ht) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->array($ht);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('pg_fetch_array() is not implemented for JIT (#20704)');
    }
}

final class pg_fetch_object extends Internal
{
    public function __construct()
    {
        parent::__construct('pg_fetch_object');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 4) {
            throw new \ArgumentCountError(\sprintf(
                'pg_fetch_object() expects between 1 and 4 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $result = VmPgsqlArg::requireResult($frame->calledArgs[0], 'pg_fetch_object', 1);
        $row = null;
        if ($argc >= 2) {
            $rowArg = $frame->calledArgs[1]->resolveIndirect();
            $row = Variable::TYPE_NULL === $rowArg->type ? null : $rowArg->toInt();
        }
        $className = 'stdClass';
        if ($argc >= 3) {
            $className = VmString::coerceStringBuiltinArg(
                $frame->calledArgs[2],
                'pg_fetch_object',
                3,
                'class'
            );
        }
        if ($argc >= 4) {
            $ctorArg = $frame->calledArgs[3]->resolveIndirect();
            if (Variable::TYPE_ARRAY !== $ctorArg->type) {
                throw new \TypeError(\sprintf(
                    'pg_fetch_object(): Argument #4 ($constructor_args) must be of type array, %s given',
                    match ($ctorArg->type) {
                        Variable::TYPE_NULL => 'null',
                        Variable::TYPE_BOOLEAN => 'bool',
                        Variable::TYPE_INTEGER => 'int',
                        Variable::TYPE_FLOAT => 'float',
                        Variable::TYPE_STRING => 'string',
                        Variable::TYPE_OBJECT => 'object',
                        Variable::TYPE_RESOURCE => 'resource',
                        default => 'mixed',
                    }
                ));
            }
            $n = 0;
            foreach ($ctorArg->toArray()->iterate(true) as $_) {
                ++$n;
            }
            if ($n > 0 && 'stdclass' === \strtolower($className)) {
                throw new \ValueError(
                    'pg_fetch_object(): Argument #4 ($constructor_args) must be empty when the specified class (stdClass) does not have a constructor'
                );
            }
            if ($n > 0) {
                throw new \LogicException(
                    'pg_fetch_object() constructor_args for custom classes are not supported in this compiler build (#20704)'
                );
            }
        }
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('pg_fetch_object() requires a VM context');
        }
        $object = VmPgsqlCore::fetchObject($result, $ctx, $row, $className);
        if (false === $object) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->object($object);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('pg_fetch_object() is not implemented for JIT (#20704)');
    }
}

final class pg_fetch_result extends Internal
{
    public function __construct()
    {
        parent::__construct('pg_fetch_result');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \ArgumentCountError(\sprintf(
                'pg_fetch_result() expects between 2 and 3 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $result = VmPgsqlArg::requireResult($frame->calledArgs[0], 'pg_fetch_result', 1);
        if (2 === $argc) {
            @\trigger_error(
                'Calling pg_fetch_result() with 2 arguments is deprecated, use the 3-parameter signature with a null $row parameter instead',
                \E_USER_DEPRECATED
            );
            $row = null;
            $fieldArg = $frame->calledArgs[1]->resolveIndirect();
            $fieldArgNum = 2;
        } else {
            $rowArg = $frame->calledArgs[1]->resolveIndirect();
            $row = Variable::TYPE_NULL === $rowArg->type ? null : $rowArg->toInt();
            $fieldArg = $frame->calledArgs[2]->resolveIndirect();
            $fieldArgNum = 3;
        }
        $field = Variable::TYPE_STRING === $fieldArg->type
            ? $fieldArg->toString()
            : $fieldArg->toInt();
        $out = VmPgsqlCore::fetchResult($result, $row, $field, $fieldArgNum);
        if (false === $out) {
            $frame->returnVar->bool(false);

            return;
        }
        if (null === $out) {
            $frame->returnVar->null();

            return;
        }
        $frame->returnVar->string($out);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('pg_fetch_result() is not implemented for JIT (#20704)');
    }
}

final class pg_free_result extends Internal
{
    public function __construct()
    {
        parent::__construct('pg_free_result');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'pg_free_result() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $result = VmPgsqlArg::requireResult($frame->calledArgs[0], 'pg_free_result', 1);
        $frame->returnVar->bool(VmPgsqlCore::freeResult($result));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('pg_free_result() is not implemented for JIT (#20704)');
    }
}

final class pg_result_seek extends Internal
{
    public function __construct()
    {
        parent::__construct('pg_result_seek');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'pg_result_seek() expects exactly 2 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $result = VmPgsqlArg::requireResult($frame->calledArgs[0], 'pg_result_seek', 1);
        $row = $frame->calledArgs[1]->resolveIndirect()->toInt();
        $frame->returnVar->bool(VmPgsqlCore::resultSeek($result, $row));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('pg_result_seek() is not implemented for JIT (#20704)');
    }
}
