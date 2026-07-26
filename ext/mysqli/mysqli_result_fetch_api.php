<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mysqli;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * Procedural mysqli_result fetch / field metadata API (#22195).
 *
 * php-src: ext/mysqli/mysqli.stub.php + mysqli_api.c
 */

abstract class MysqliResultProceduralBuiltin extends Internal
{
    protected function resultNative(Frame $frame): \mysqli_result
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \ArgumentCountError(\sprintf('%s() expects at least 1 argument, 0 given', $this->name));
        }

        return VmMysqliResult::requireNative(
            VmMysqliResult::requireResultObject($frame->calledArgs[0], $this->name)
        );
    }

    protected function optionalMode(Frame $frame, int $default): int
    {
        if (\count($frame->calledArgs) < 2) {
            return $default;
        }
        $modeVar = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_INTEGER === $modeVar->type) {
            return $modeVar->toInt();
        }
        if (Variable::TYPE_FLOAT === $modeVar->type) {
            return (int) $modeVar->toFloat();
        }

        return $default;
    }

    protected function intArgAt(Frame $frame, int $index, string $param): int
    {
        if (\count($frame->calledArgs) <= $index) {
            throw new \ArgumentCountError(\sprintf(
                '%s() expects at least %d arguments, %d given',
                $this->name,
                $index + 1,
                \count($frame->calledArgs)
            ));
        }
        $resolved = $frame->calledArgs[$index]->resolveIndirect();
        if (Variable::TYPE_INTEGER === $resolved->type) {
            return $resolved->toInt();
        }
        if (Variable::TYPE_FLOAT === $resolved->type) {
            return (int) $resolved->toFloat();
        }
        if (Variable::TYPE_BOOLEAN === $resolved->type) {
            return $resolved->toBool() ? 1 : 0;
        }
        if (Variable::TYPE_STRING === $resolved->type && is_numeric($resolved->toString())) {
            return (int) $resolved->toString();
        }

        throw new \TypeError(\sprintf(
            '%s(): Argument #%d ($%s) must be of type int, %s given',
            $this->name,
            $index + 1,
            $param,
            MysqliClassMethod::typeLabelPublic($resolved)
        ));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error($this->name.'() is not implemented for JIT (issue #22195)');
    }
}

/** mysqli_fetch_column() — php-src ext/mysqli/mysqli_nonapi.c (#22214). */
final class mysqli_fetch_column extends MysqliResultProceduralBuiltin
{
    public function __construct()
    {
        parent::__construct('mysqli_fetch_column');
    }

    public function execute(Frame $frame): void
    {
        $this->requireArgCountRange($frame, 'mysqli_fetch_column', 1, 2);
        $native = $this->resultNative($frame);
        $column = 0;
        if (\count($frame->calledArgs) >= 2) {
            $column = $this->intArgAt($frame, 1, 'column');
        }
        if (null === $frame->returnVar) {
            return;
        }
        VmMysqliResult::assignFetchColumnResult(
            $frame->returnVar,
            VmMysqliResult::fetchColumn($native, $column, 'mysqli_fetch_column', 2)
        );
    }
}

/** mysqli_fetch_all() — php-src ext/mysqli/mysqli_api.c (#22195). */
final class mysqli_fetch_all extends MysqliResultProceduralBuiltin
{
    public function __construct()
    {
        parent::__construct('mysqli_fetch_all');
    }

    public function execute(Frame $frame): void
    {
        $native = $this->resultNative($frame);
        $mode = $this->optionalMode($frame, MysqliConstants::MYSQLI_NUM);
        if (null === $frame->returnVar) {
            return;
        }
        VmMysqliResult::assignRows($frame->returnVar, VmMysqliResult::fetchAllRows($native, $mode));
    }
}

/** mysqli_fetch_object() — php-src ext/mysqli/mysqli_api.c (#22195). */
final class mysqli_fetch_object extends MysqliResultProceduralBuiltin
{
    public function __construct()
    {
        parent::__construct('mysqli_fetch_object');
    }

    public function execute(Frame $frame): void
    {
        $native = $this->resultNative($frame);
        $ctx = $frame->vmContext ?? throw new \LogicException('mysqli_fetch_object() requires VM context');
        $class = 'stdClass';
        $ctorArgs = [];
        if (\count($frame->calledArgs) >= 2) {
            $classVar = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_STRING === $classVar->type) {
                $class = $classVar->toString();
            } elseif (Variable::TYPE_NULL !== $classVar->type) {
                $class = $classVar->toString();
            }
        }
        if (\count($frame->calledArgs) >= 3) {
            $ctorVar = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_ARRAY === $ctorVar->type) {
                foreach ($ctorVar->toArray()->iterate(true) as $itemVar) {
                    $ctorArgs[] = match ($itemVar->type) {
                        Variable::TYPE_NULL => null,
                        Variable::TYPE_BOOLEAN => $itemVar->toBool(),
                        Variable::TYPE_INTEGER => $itemVar->toInt(),
                        Variable::TYPE_FLOAT => $itemVar->toFloat(),
                        default => $itemVar->toString(),
                    };
                }
            }
        }
        if (null === $frame->returnVar) {
            return;
        }
        $obj = VmMysqliResult::fetchObject($ctx, $native, $class, $ctorArgs);
        if (null === $obj) {
            $frame->returnVar->null();
        } else {
            $frame->returnVar->object($obj);
        }
    }
}

/** mysqli_fetch_field() — php-src ext/mysqli/mysqli_api.c (#22195). */
final class mysqli_fetch_field extends MysqliResultProceduralBuiltin
{
    public function __construct()
    {
        parent::__construct('mysqli_fetch_field');
    }

    public function execute(Frame $frame): void
    {
        $native = $this->resultNative($frame);
        $ctx = $frame->vmContext ?? throw new \LogicException('mysqli_fetch_field() requires VM context');
        if (null === $frame->returnVar) {
            return;
        }
        $field = VmMysqliResult::fetchField($native, $ctx);
        if (null === $field) {
            $frame->returnVar->null();
        } else {
            $frame->returnVar->object($field);
        }
    }
}

/** mysqli_fetch_fields() — php-src ext/mysqli/mysqli_api.c (#22195). */
final class mysqli_fetch_fields extends MysqliResultProceduralBuiltin
{
    public function __construct()
    {
        parent::__construct('mysqli_fetch_fields');
    }

    public function execute(Frame $frame): void
    {
        $native = $this->resultNative($frame);
        $ctx = $frame->vmContext ?? throw new \LogicException('mysqli_fetch_fields() requires VM context');
        if (null === $frame->returnVar) {
            return;
        }
        $ht = new HashTable();
        foreach (VmMysqliResult::fetchFields($native, $ctx) as $i => $field) {
            $slot = new Variable();
            $slot->object($field);
            $ht->add((string) $i, $slot);
        }
        $frame->returnVar->array($ht);
    }
}

/** mysqli_fetch_field_direct() — php-src ext/mysqli/mysqli_api.c (#22195). */
final class mysqli_fetch_field_direct extends MysqliResultProceduralBuiltin
{
    public function __construct()
    {
        parent::__construct('mysqli_fetch_field_direct');
    }

    public function execute(Frame $frame): void
    {
        $native = $this->resultNative($frame);
        $ctx = $frame->vmContext ?? throw new \LogicException('mysqli_fetch_field_direct() requires VM context');
        $index = $this->intArgAt($frame, 1, 'row');
        if (null === $frame->returnVar) {
            return;
        }
        $field = VmMysqliResult::fetchFieldDirect($native, $ctx, $index);
        if (null === $field) {
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->object($field);
        }
    }
}

/** mysqli_fetch_lengths() — php-src ext/mysqli/mysqli_api.c (#22195). */
final class mysqli_fetch_lengths extends MysqliResultProceduralBuiltin
{
    public function __construct()
    {
        parent::__construct('mysqli_fetch_lengths');
    }

    public function execute(Frame $frame): void
    {
        $native = $this->resultNative($frame);
        if (null === $frame->returnVar) {
            return;
        }
        $lengths = VmMysqliResult::fetchLengths($native);
        if (null === $lengths) {
            $frame->returnVar->bool(false);
        } else {
            $ht = new HashTable();
            foreach ($lengths as $i => $len) {
                $slot = new Variable();
                $slot->int($len);
                $ht->add((string) $i, $slot);
            }
            $frame->returnVar->array($ht);
        }
    }
}

/** mysqli_data_seek() — php-src ext/mysqli/mysqli_api.c (#22195). */
final class mysqli_data_seek extends MysqliResultProceduralBuiltin
{
    public function __construct()
    {
        parent::__construct('mysqli_data_seek');
    }

    public function execute(Frame $frame): void
    {
        $native = $this->resultNative($frame);
        $offset = $this->intArgAt($frame, 1, 'offset');
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($native->data_seek($offset));
        }
    }
}

/** mysqli_field_seek() — php-src ext/mysqli/mysqli_api.c (#22195). */
final class mysqli_field_seek extends MysqliResultProceduralBuiltin
{
    public function __construct()
    {
        parent::__construct('mysqli_field_seek');
    }

    public function execute(Frame $frame): void
    {
        $native = $this->resultNative($frame);
        $index = $this->intArgAt($frame, 1, 'index');
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($native->field_seek($index));
        }
    }
}

/** mysqli_field_tell() — php-src ext/mysqli/mysqli_api.c (#22195). */
final class mysqli_field_tell extends MysqliResultProceduralBuiltin
{
    public function __construct()
    {
        parent::__construct('mysqli_field_tell');
    }

    public function execute(Frame $frame): void
    {
        $native = $this->resultNative($frame);
        if (null !== $frame->returnVar) {
            $frame->returnVar->int($native->current_field);
        }
    }
}

/** mysqli_num_fields() — php-src ext/mysqli/mysqli_api.c (#22195). */
final class mysqli_num_fields extends MysqliResultProceduralBuiltin
{
    public function __construct()
    {
        parent::__construct('mysqli_num_fields');
    }

    public function execute(Frame $frame): void
    {
        $native = $this->resultNative($frame);
        if (null !== $frame->returnVar) {
            $frame->returnVar->int($native->field_count);
        }
    }
}
