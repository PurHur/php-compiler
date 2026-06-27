<?php

declare(strict_types=1);

namespace PHPCompiler\ext\spl;

use PHPCompiler\ext\standard\VmMath;
use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;
use PHPCfg\Func as CfgFunc;

/**
 * SplFixedArray — fixed-size array access (php-src ext/spl/spl_fixedarray.c; #12628).
 */
final class SplFixedArrayBuiltin
{
    public const CLASS_LC = 'splfixedarray';

    private const RANGE_ERROR = 'Index invalid or out of range';

    /** @var array<int, array{size: int, slots: array<int, Variable>}> */
    private static array $store = [];

    public static function registerClass(Context $ctx): void
    {
        if (isset($ctx->classes[self::CLASS_LC]) && self::classIsComplete($ctx->classes[self::CLASS_LC])) {
            return;
        }

        $pub = CfgFunc::FLAG_PUBLIC;
        $entry = isset($ctx->classes[self::CLASS_LC])
            ? $ctx->classes[self::CLASS_LC]
            : new ClassEntry('SplFixedArray');
        foreach (['IteratorAggregate', 'Traversable', 'ArrayAccess', 'Countable', 'JsonSerializable'] as $iface) {
            if (isset($ctx->classes[strtolower($iface)])
                && !\in_array($iface, $entry->interfaces, true)) {
                $entry->interfaces[] = $iface;
            }
        }

        $entry->constructor = new SplFixedArrayConstruct();
        $entry->methods['__construct'] = $entry->constructor;
        $entry->methodVisibility['__construct'] = $pub;
        foreach ([
            'count' => SplFixedArrayCount::class,
            'offsetget' => SplFixedArrayOffsetGet::class,
            'offsetset' => SplFixedArrayOffsetSet::class,
            'offsetexists' => SplFixedArrayOffsetExists::class,
            'offsetunset' => SplFixedArrayOffsetUnset::class,
        ] as $lc => $class) {
            $entry->methods[$lc] = new $class();
            $entry->methodVisibility[$lc] = $pub;
        }
        $entry->methodNames['offsetget'] = 'offsetGet';
        $entry->methodNames['offsetset'] = 'offsetSet';
        $entry->methodNames['offsetexists'] = 'offsetExists';
        $entry->methodNames['offsetunset'] = 'offsetUnset';

        $entry->isInternal = true;
        $ctx->classes[self::CLASS_LC] = $entry;
    }

    private static function classIsComplete(ClassEntry $entry): bool
    {
        return isset($entry->methods['count'], $entry->methods['offsetexists']);
    }

    public static function init(ObjectEntry $object, int $size): void
    {
        if ($size < 0) {
            throw new \ValueError('SplFixedArray::__construct(): Argument #1 ($size) must be greater than or equal to 0');
        }
        self::$store[$object->id] = ['size' => $size, 'slots' => []];
    }

    public static function count(ObjectEntry $object): int
    {
        return self::state($object)['size'];
    }

    public static function offsetExists(ObjectEntry $object, Variable $offset): bool
    {
        $index = self::coerceOffset($offset);
        if (!self::indexInRange($object, $index)) {
            return false;
        }

        return isset(self::state($object)['slots'][$index]);
    }

    public static function offsetGet(ObjectEntry $object, Variable $offset): Variable
    {
        $index = self::coerceOffset($offset);
        if (!self::indexInRange($object, $index)) {
            throw new \RuntimeException(self::RANGE_ERROR);
        }
        $result = new Variable();
        if (isset(self::state($object)['slots'][$index])) {
            $result->copyFrom(self::state($object)['slots'][$index]);
        } else {
            $result->null();
        }

        return $result;
    }

    public static function offsetSet(ObjectEntry $object, Variable $offset, Variable $value): void
    {
        $index = self::coerceOffset($offset);
        if (!self::indexInRange($object, $index)) {
            throw new \RuntimeException(self::RANGE_ERROR);
        }
        $copy = new Variable();
        $copy->copyFrom($value->resolveIndirect());
        self::$store[$object->id]['slots'][$index] = $copy;
    }

    public static function offsetUnset(ObjectEntry $object, Variable $offset): void
    {
        $index = self::coerceOffset($offset);
        if (!self::indexInRange($object, $index)) {
            throw new \RuntimeException(self::RANGE_ERROR);
        }
        unset(self::$store[$object->id]['slots'][$index]);
    }

    /** @return array{size: int, slots: array<int, Variable>} */
    private static function state(ObjectEntry $object): array
    {
        if (!isset(self::$store[$object->id])) {
            throw new \LogicException('SplFixedArray object state missing');
        }

        return self::$store[$object->id];
    }

    private static function indexInRange(ObjectEntry $object, int $index): bool
    {
        return $index >= 0 && $index < self::state($object)['size'];
    }

    private static function coerceOffset(Variable $offset): int
    {
        $resolved = $offset->resolveIndirect();
        if (Variable::TYPE_INTEGER !== $resolved->type) {
            throw new \TypeError(
                'SplFixedArray offset must be of type int, '
                .match ($resolved->type) {
                    Variable::TYPE_NULL => 'null',
                    Variable::TYPE_BOOL => 'bool',
                    Variable::TYPE_DOUBLE => 'float',
                    Variable::TYPE_STRING => 'string',
                    Variable::TYPE_ARRAY => 'array',
                    Variable::TYPE_OBJECT => 'object',
                    default => 'mixed',
                }.' given'
            );
        }

        return $resolved->toInt();
    }
}

final class SplFixedArrayConstruct extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiver(
            $frame,
            SplFixedArrayBuiltin::CLASS_LC,
            'SplFixedArray::__construct()'
        );
        $size = 0;
        if (isset($frame->calledArgs[1])) {
            $size = VmMath::parseIntBuiltinArg(
                $frame->calledArgs[1],
                'SplFixedArray::__construct',
                0,
                'size'
            );
        }
        SplFixedArrayBuiltin::init($object, $size);
    }
}

final class SplFixedArrayCount extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('count');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiver(
            $frame,
            SplFixedArrayBuiltin::CLASS_LC,
            'SplFixedArray::count()'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int(SplFixedArrayBuiltin::count($object));
    }
}

final class SplFixedArrayOffsetGet extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('offsetGet');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiver(
            $frame,
            SplFixedArrayBuiltin::CLASS_LC,
            'SplFixedArray::offsetGet()'
        );
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError(
                'SplFixedArray::offsetGet() expects exactly 1 argument, '
                .(\count($frame->calledArgs) - 1).' given'
            );
        }
        SplIteratorSupport::copyReturnFrom(
            $frame,
            SplFixedArrayBuiltin::offsetGet($object, $frame->calledArgs[1])
        );
    }
}

final class SplFixedArrayOffsetSet extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('offsetSet');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiver(
            $frame,
            SplFixedArrayBuiltin::CLASS_LC,
            'SplFixedArray::offsetSet()'
        );
        if (\count($frame->calledArgs) < 3) {
            throw new \ArgumentCountError(
                'SplFixedArray::offsetSet() expects exactly 2 arguments, '
                .(\count($frame->calledArgs) - 1).' given'
            );
        }
        SplFixedArrayBuiltin::offsetSet($object, $frame->calledArgs[1], $frame->calledArgs[2]);
    }
}

final class SplFixedArrayOffsetExists extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('offsetExists');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiver(
            $frame,
            SplFixedArrayBuiltin::CLASS_LC,
            'SplFixedArray::offsetExists()'
        );
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError(
                'SplFixedArray::offsetExists() expects exactly 1 argument, '
                .(\count($frame->calledArgs) - 1).' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool(
            SplFixedArrayBuiltin::offsetExists($object, $frame->calledArgs[1])
        );
    }
}

final class SplFixedArrayOffsetUnset extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('offsetUnset');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiver(
            $frame,
            SplFixedArrayBuiltin::CLASS_LC,
            'SplFixedArray::offsetUnset()'
        );
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError(
                'SplFixedArray::offsetUnset() expects exactly 1 argument, '
                .(\count($frame->calledArgs) - 1).' given'
            );
        }
        SplFixedArrayBuiltin::offsetUnset($object, $frame->calledArgs[1]);
    }
}
