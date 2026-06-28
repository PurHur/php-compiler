<?php

declare(strict_types=1);

namespace PHPCompiler\ext\spl;

use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;
use PHPCfg\Func as CfgFunc;

/**
 * SplDoublyLinkedList — deque push/pop/shift/unshift (php-src ext/spl/spl_dllist.c; #13080).
 */
final class SplDoublyLinkedListBuiltin
{
    public const CLASS_LC = 'spldoublylinkedlist';

    /** @var array<int, list<Variable>> */
    private static array $store = [];

    public static function registerClass(Context $ctx): void
    {
        if (isset($ctx->classes[self::CLASS_LC]) && self::classIsComplete($ctx->classes[self::CLASS_LC])) {
            return;
        }

        $pub = CfgFunc::FLAG_PUBLIC;
        $entry = isset($ctx->classes[self::CLASS_LC])
            ? $ctx->classes[self::CLASS_LC]
            : new ClassEntry('SplDoublyLinkedList');
        foreach (['iterator', 'traversable', 'countable', 'arrayaccess', 'serializable'] as $iface) {
            if (isset($ctx->classes[$iface])
                && !\in_array($iface, $entry->interfaces, true)) {
                $entry->interfaces[] = $iface;
            }
        }

        $entry->constructor = new SplDoublyLinkedListConstruct();
        $entry->methods['__construct'] = $entry->constructor;
        $entry->methodVisibility['__construct'] = $pub;
        foreach ([
            'push' => SplDoublyLinkedListPush::class,
            'pop' => SplDoublyLinkedListPop::class,
            'shift' => SplDoublyLinkedListShift::class,
            'unshift' => SplDoublyLinkedListUnshift::class,
            'count' => SplDoublyLinkedListCount::class,
            'offsetget' => SplDoublyLinkedListOffsetGet::class,
            'offsetset' => SplDoublyLinkedListOffsetSet::class,
            'offsetexists' => SplDoublyLinkedListOffsetExists::class,
            'offsetunset' => SplDoublyLinkedListOffsetUnset::class,
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
        return isset($entry->methods['push'], $entry->methods['pop'], $entry->methods['offsetget']);
    }

    public static function init(ObjectEntry $object): void
    {
        self::$store[$object->id] = [];
    }

    /** @return list<Variable> */
    private static function state(ObjectEntry $object): array
    {
        if (!isset(self::$store[$object->id])) {
            self::init($object);
        }

        return self::$store[$object->id];
    }

    public static function push(ObjectEntry $object, Variable $value): void
    {
        $copy = new Variable();
        $copy->copyFrom($value->resolveIndirect());
        self::$store[$object->id][] = $copy;
    }

    public static function pop(ObjectEntry $object): Variable
    {
        $state = self::state($object);
        if ([] === $state) {
            throw new \RuntimeException("Can't pop from an empty datastructure");
        }
        $last = \array_pop(self::$store[$object->id]);
        $result = new Variable();
        $result->copyFrom($last);

        return $result;
    }

    public static function shift(ObjectEntry $object): Variable
    {
        $state = self::state($object);
        if ([] === $state) {
            throw new \RuntimeException("Can't shift from an empty datastructure");
        }
        $first = \array_shift(self::$store[$object->id]);
        $result = new Variable();
        $result->copyFrom($first);

        return $result;
    }

    public static function unshift(ObjectEntry $object, Variable $value): void
    {
        $copy = new Variable();
        $copy->copyFrom($value->resolveIndirect());
        \array_unshift(self::$store[$object->id], $copy);
    }

    public static function count(ObjectEntry $object): int
    {
        return \count(self::state($object));
    }

    public static function offsetExists(ObjectEntry $object, Variable $offset): bool
    {
        $index = self::coerceIndex($offset, 'offsetExists', true);
        $count = \count(self::state($object));

        return $index >= 0 && $index < $count;
    }

    public static function offsetGet(ObjectEntry $object, Variable $offset): Variable
    {
        $index = self::coerceIndex($offset, 'offsetGet', false);
        $state = self::state($object);
        if ($index < 0 || $index >= \count($state)) {
            throw new \OutOfRangeException('SplDoublyLinkedList::offsetGet(): Argument #1 ($index) is out of range');
        }
        $result = new Variable();
        $result->copyFrom($state[$index]);

        return $result;
    }

    public static function offsetSet(ObjectEntry $object, Variable $offset, Variable $value): void
    {
        $index = self::coerceIndex($offset, 'offsetSet', true);
        $count = \count(self::state($object));
        if ($index < 0 || $index >= $count) {
            throw new \OutOfRangeException('SplDoublyLinkedList::offsetSet(): Argument #1 ($index) is out of range');
        }
        $copy = new Variable();
        $copy->copyFrom($value->resolveIndirect());
        self::$store[$object->id][$index] = $copy;
    }

    public static function offsetUnset(ObjectEntry $object, Variable $offset): void
    {
        $index = self::coerceIndex($offset, 'offsetUnset', false);
        $count = \count(self::state($object));
        if ($index < 0 || $index >= $count) {
            throw new \OutOfRangeException('SplDoublyLinkedList::offsetUnset(): Argument #1 ($index) is out of range');
        }
        \array_splice(self::$store[$object->id], $index, 1);
    }

    private static function coerceIndex(Variable $offset, string $method, bool $nullable): int
    {
        $resolved = $offset->resolveIndirect();
        if (Variable::TYPE_NULL === $resolved->type) {
            if (!$nullable) {
                throw new \TypeError(
                    'SplDoublyLinkedList::'.$method.'(): Argument #1 ($index) must be of type int, null given'
                );
            }

            return 0;
        }
        if (Variable::TYPE_INTEGER !== $resolved->type) {
            $typeName = match ($resolved->type) {
                Variable::TYPE_BOOL => 'bool',
                Variable::TYPE_DOUBLE => 'float',
                Variable::TYPE_STRING => 'string',
                Variable::TYPE_ARRAY => 'array',
                Variable::TYPE_OBJECT => 'object',
                default => 'mixed',
            };
            $expected = $nullable ? '?int' : 'int';
            throw new \TypeError(
                'SplDoublyLinkedList::'.$method.'(): Argument #1 ($index) must be of type '.$expected.', '.$typeName.' given'
            );
        }

        return $resolved->toInt();
    }
}

final class SplDoublyLinkedListConstruct extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiver(
            $frame,
            SplDoublyLinkedListBuiltin::CLASS_LC,
            'SplDoublyLinkedList::__construct()'
        );
        SplDoublyLinkedListBuiltin::init($object);
    }
}

final class SplDoublyLinkedListPush extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('push');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplDoublyLinkedListBuiltin::CLASS_LC,
            'SplDoublyLinkedList::push()'
        );
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError(
                'SplDoublyLinkedList::push() expects exactly 1 argument, '
                .(\count($frame->calledArgs) - 1).' given'
            );
        }
        SplDoublyLinkedListBuiltin::push($object, $frame->calledArgs[1]);
    }
}

final class SplDoublyLinkedListPop extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('pop');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplDoublyLinkedListBuiltin::CLASS_LC,
            'SplDoublyLinkedList::pop()'
        );
        SplIteratorSupport::copyReturnFrom($frame, SplDoublyLinkedListBuiltin::pop($object));
    }
}

final class SplDoublyLinkedListShift extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('shift');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplDoublyLinkedListBuiltin::CLASS_LC,
            'SplDoublyLinkedList::shift()'
        );
        SplIteratorSupport::copyReturnFrom($frame, SplDoublyLinkedListBuiltin::shift($object));
    }
}

final class SplDoublyLinkedListUnshift extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('unshift');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplDoublyLinkedListBuiltin::CLASS_LC,
            'SplDoublyLinkedList::unshift()'
        );
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError(
                'SplDoublyLinkedList::unshift() expects exactly 1 argument, '
                .(\count($frame->calledArgs) - 1).' given'
            );
        }
        SplDoublyLinkedListBuiltin::unshift($object, $frame->calledArgs[1]);
    }
}

final class SplDoublyLinkedListCount extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('count');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplDoublyLinkedListBuiltin::CLASS_LC,
            'SplDoublyLinkedList::count()'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int(SplDoublyLinkedListBuiltin::count($object));
    }
}

final class SplDoublyLinkedListOffsetGet extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('offsetGet');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplDoublyLinkedListBuiltin::CLASS_LC,
            'SplDoublyLinkedList::offsetGet()'
        );
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError(
                'SplDoublyLinkedList::offsetGet() expects exactly 1 argument, '
                .(\count($frame->calledArgs) - 1).' given'
            );
        }
        SplIteratorSupport::copyReturnFrom(
            $frame,
            SplDoublyLinkedListBuiltin::offsetGet($object, $frame->calledArgs[1])
        );
    }
}

final class SplDoublyLinkedListOffsetSet extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('offsetSet');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplDoublyLinkedListBuiltin::CLASS_LC,
            'SplDoublyLinkedList::offsetSet()'
        );
        if (\count($frame->calledArgs) < 3) {
            throw new \ArgumentCountError(
                'SplDoublyLinkedList::offsetSet() expects exactly 2 arguments, '
                .(\count($frame->calledArgs) - 1).' given'
            );
        }
        SplDoublyLinkedListBuiltin::offsetSet($object, $frame->calledArgs[1], $frame->calledArgs[2]);
    }
}

final class SplDoublyLinkedListOffsetExists extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('offsetExists');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplDoublyLinkedListBuiltin::CLASS_LC,
            'SplDoublyLinkedList::offsetExists()'
        );
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError(
                'SplDoublyLinkedList::offsetExists() expects exactly 1 argument, '
                .(\count($frame->calledArgs) - 1).' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool(
            SplDoublyLinkedListBuiltin::offsetExists($object, $frame->calledArgs[1])
        );
    }
}

final class SplDoublyLinkedListOffsetUnset extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('offsetUnset');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplDoublyLinkedListBuiltin::CLASS_LC,
            'SplDoublyLinkedList::offsetUnset()'
        );
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError(
                'SplDoublyLinkedList::offsetUnset() expects exactly 1 argument, '
                .(\count($frame->calledArgs) - 1).' given'
            );
        }
        SplDoublyLinkedListBuiltin::offsetUnset($object, $frame->calledArgs[1]);
    }
}
