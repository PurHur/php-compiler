<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mysqli;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * Procedural mysqli_stmt introspection API (#22193).
 *
 * php-src: ext/mysqli/mysqli.stub.php + mysqli_api.c
 */

abstract class MysqliStmtIntrospectionBuiltin extends Internal
{
    protected function stmt(Frame $frame): \PHPCompiler\VM\ObjectEntry
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \ArgumentCountError(\sprintf('%s() expects at least 1 argument, 0 given', $this->getName()));
        }

        return VmMysqliStmt::requireStmtObject($frame->calledArgs[0], $this->getName());
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error($this->getName().'() is not implemented for JIT (issue #22193)');
    }
}

final class mysqli_stmt_field_count extends MysqliStmtIntrospectionBuiltin
{
    public function __construct()
    {
        parent::__construct('mysqli_stmt_field_count');
    }

    public function execute(Frame $frame): void
    {
        if (null !== $frame->returnVar) {
            $frame->returnVar->int(VmMysqliStmt::fieldCount($this->stmt($frame)));
        }
    }
}

final class mysqli_stmt_param_count extends MysqliStmtIntrospectionBuiltin
{
    public function __construct()
    {
        parent::__construct('mysqli_stmt_param_count');
    }

    public function execute(Frame $frame): void
    {
        if (null !== $frame->returnVar) {
            $frame->returnVar->int(VmMysqliStmt::paramCount($this->stmt($frame)));
        }
    }
}

final class mysqli_stmt_sqlstate extends MysqliStmtIntrospectionBuiltin
{
    public function __construct()
    {
        parent::__construct('mysqli_stmt_sqlstate');
    }

    public function execute(Frame $frame): void
    {
        if (null !== $frame->returnVar) {
            $frame->returnVar->string(VmMysqliStmt::sqlstate($this->stmt($frame)));
        }
    }
}

final class mysqli_stmt_errno extends MysqliStmtIntrospectionBuiltin
{
    public function __construct()
    {
        parent::__construct('mysqli_stmt_errno');
    }

    public function execute(Frame $frame): void
    {
        if (null !== $frame->returnVar) {
            $frame->returnVar->int(VmMysqliStmt::errno($this->stmt($frame)));
        }
    }
}

final class mysqli_stmt_error extends MysqliStmtIntrospectionBuiltin
{
    public function __construct()
    {
        parent::__construct('mysqli_stmt_error');
    }

    public function execute(Frame $frame): void
    {
        if (null !== $frame->returnVar) {
            $frame->returnVar->string(VmMysqliStmt::error($this->stmt($frame)));
        }
    }
}

final class mysqli_stmt_insert_id extends MysqliStmtIntrospectionBuiltin
{
    public function __construct()
    {
        parent::__construct('mysqli_stmt_insert_id');
    }

    public function execute(Frame $frame): void
    {
        if (null !== $frame->returnVar) {
            VmMysqli::assignInsertId($frame->returnVar, VmMysqliStmt::insertId($this->stmt($frame)));
        }
    }
}

final class mysqli_stmt_num_rows extends MysqliStmtIntrospectionBuiltin
{
    public function __construct()
    {
        parent::__construct('mysqli_stmt_num_rows');
    }

    public function execute(Frame $frame): void
    {
        if (null !== $frame->returnVar) {
            $frame->returnVar->int(VmMysqliStmt::numRows($this->stmt($frame)));
        }
    }
}

final class mysqli_stmt_affected_rows extends MysqliStmtIntrospectionBuiltin
{
    public function __construct()
    {
        parent::__construct('mysqli_stmt_affected_rows');
    }

    public function execute(Frame $frame): void
    {
        if (null !== $frame->returnVar) {
            $frame->returnVar->int(VmMysqliStmt::affectedRows($this->stmt($frame)));
        }
    }
}

final class mysqli_stmt_data_seek extends MysqliStmtIntrospectionBuiltin
{
    public function __construct()
    {
        parent::__construct('mysqli_stmt_data_seek');
    }

    public function execute(Frame $frame): void
    {
        $stmt = $this->stmt($frame);
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('mysqli_stmt_data_seek() expects exactly 2 arguments, 1 given');
        }
        $offsetVar = $frame->calledArgs[1]->resolveIndirect();
        $offset = match ($offsetVar->type) {
            Variable::TYPE_INTEGER => $offsetVar->toInt(),
            Variable::TYPE_FLOAT => (int) $offsetVar->toFloat(),
            Variable::TYPE_BOOLEAN => $offsetVar->toBool() ? 1 : 0,
            Variable::TYPE_STRING => is_numeric($offsetVar->toString()) ? (int) $offsetVar->toString() : 0,
            default => 0,
        };
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(VmMysqliStmt::dataSeek($stmt, $offset));
        }
    }
}

final class mysqli_stmt_reset extends MysqliStmtIntrospectionBuiltin
{
    public function __construct()
    {
        parent::__construct('mysqli_stmt_reset');
    }

    public function execute(Frame $frame): void
    {
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(VmMysqliStmt::reset($this->stmt($frame)));
        }
    }
}

final class mysqli_stmt_store_result extends MysqliStmtIntrospectionBuiltin
{
    public function __construct()
    {
        parent::__construct('mysqli_stmt_store_result');
    }

    public function execute(Frame $frame): void
    {
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(VmMysqliStmt::storeResult($this->stmt($frame)));
        }
    }
}

/** mysqli_stmt_get_result() — php-src ext/mysqli/mysqli_stmt.c (#22162). */
final class mysqli_stmt_get_result extends MysqliStmtIntrospectionBuiltin
{
    public function __construct()
    {
        parent::__construct('mysqli_stmt_get_result');
    }

    public function execute(Frame $frame): void
    {
        if (null === $frame->returnVar) {
            return;
        }
        $result = VmMysqliStmt::getResult($this->stmt($frame));
        if (false === $result) {
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->object($result);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error($this->getName().'() is not implemented for JIT (issue #22162)');
    }
}

final class mysqli_stmt_free_result extends MysqliStmtIntrospectionBuiltin
{
    public function __construct()
    {
        parent::__construct('mysqli_stmt_free_result');
    }

    public function execute(Frame $frame): void
    {
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(VmMysqliStmt::freeResult($this->stmt($frame)));
        }
    }
}

final class mysqli_stmt_result_metadata extends MysqliStmtIntrospectionBuiltin
{
    public function __construct()
    {
        parent::__construct('mysqli_stmt_result_metadata');
    }

    public function execute(Frame $frame): void
    {
        if (null === $frame->returnVar) {
            return;
        }
        $meta = VmMysqliStmt::resultMetadata($this->stmt($frame));
        if (false === $meta) {
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->object($meta);
        }
    }
}

/** mysqli_stmt_attr_get() — php-src ext/mysqli/mysqli_api.c (#22175). */
final class mysqli_stmt_attr_get extends MysqliStmtIntrospectionBuiltin
{
    public function __construct()
    {
        parent::__construct('mysqli_stmt_attr_get');
    }

    public function execute(Frame $frame): void
    {
        $stmt = $this->stmt($frame);
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('mysqli_stmt_attr_get() expects exactly 2 arguments, 1 given');
        }
        $attributeVar = $frame->calledArgs[1]->resolveIndirect();
        $attribute = match ($attributeVar->type) {
            Variable::TYPE_INTEGER => $attributeVar->toInt(),
            Variable::TYPE_FLOAT => (int) $attributeVar->toFloat(),
            Variable::TYPE_BOOLEAN => $attributeVar->toBool() ? 1 : 0,
            Variable::TYPE_STRING => is_numeric($attributeVar->toString()) ? (int) $attributeVar->toString() : 0,
            default => throw new \TypeError(\sprintf(
                'mysqli_stmt_attr_get(): Argument #2 ($attribute) must be of type int, %s given',
                MysqliClassMethod::typeLabelPublic($attributeVar)
            )),
        };
        if (null !== $frame->returnVar) {
            $frame->returnVar->int(VmMysqliStmt::attrGet($stmt, $attribute, 'mysqli_stmt_attr_get', 2));
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error($this->getName().'() is not implemented for JIT (issue #22175)');
    }
}

/** mysqli_stmt_attr_set() — php-src ext/mysqli/mysqli_api.c (#22175). */
final class mysqli_stmt_attr_set extends MysqliStmtIntrospectionBuiltin
{
    public function __construct()
    {
        parent::__construct('mysqli_stmt_attr_set');
    }

    public function execute(Frame $frame): void
    {
        $stmt = $this->stmt($frame);
        if (\count($frame->calledArgs) < 3) {
            throw new \ArgumentCountError(
                'mysqli_stmt_attr_set() expects exactly 3 arguments, '.\count($frame->calledArgs).' given'
            );
        }
        $attribute = $this->intParam($frame->calledArgs[1], 2, 'attribute');
        $value = $this->intParam($frame->calledArgs[2], 3, 'value');
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(VmMysqliStmt::attrSet($stmt, $attribute, $value, 'mysqli_stmt_attr_set', 2, 3));
        }
    }

    private function intParam(Variable $var, int $argPos, string $name): int
    {
        $resolved = $var->resolveIndirect();

        return match ($resolved->type) {
            Variable::TYPE_INTEGER => $resolved->toInt(),
            Variable::TYPE_FLOAT => (int) $resolved->toFloat(),
            Variable::TYPE_BOOLEAN => $resolved->toBool() ? 1 : 0,
            Variable::TYPE_STRING => is_numeric($resolved->toString()) ? (int) $resolved->toString() : 0,
            default => throw new \TypeError(\sprintf(
                'mysqli_stmt_attr_set(): Argument #%d ($%s) must be of type int, %s given',
                $argPos,
                $name,
                MysqliClassMethod::typeLabelPublic($resolved)
            )),
        };
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error($this->getName().'() is not implemented for JIT (issue #22175)');
    }
}
