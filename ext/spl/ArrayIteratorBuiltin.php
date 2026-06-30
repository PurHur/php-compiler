<?php

declare(strict_types=1);

namespace PHPCompiler\ext\spl;

use PHPCompiler\ext\standard\StdlibConstants;
use PHPCompiler\ext\standard\VmInternalCompare;
use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;
use PHPCfg\Func as CfgFunc;

/**
 * ArrayIterator — array as Iterator (php-src ext/spl/spl_array.c; #6304, #10711).
 */
final class ArrayIteratorBuiltin
{
    public const CLASS_LC = 'arrayiterator';

    public static function registerClass(Context $ctx): void
    {
        if (isset($ctx->classes[self::CLASS_LC])) {
            return;
        }

        $pub = CfgFunc::FLAG_PUBLIC;
        $entry = new ClassEntry('ArrayIterator');
        if (isset($ctx->classes['iterator'])) {
            $entry->interfaces[] = 'iterator';
        }
        if (isset($ctx->classes['countable'])) {
            $entry->interfaces[] = 'countable';
        }
        if (isset($ctx->classes['arrayaccess'])) {
            $entry->interfaces[] = 'arrayaccess';
        }
        if (isset($ctx->classes['seekableiterator'])) {
            $entry->interfaces[] = 'seekableiterator';
        }
        if (isset($ctx->classes['serializable'])) {
            $entry->interfaces[] = 'serializable';
        }

        $entry->constructor = new ArrayIteratorConstruct();
        $entry->methods['__construct'] = $entry->constructor;
        $entry->methodVisibility['__construct'] = $pub;
        $entry->methods['current'] = new ArrayIteratorCurrent();
        $entry->methodVisibility['current'] = $pub;
        $entry->methods['key'] = new ArrayIteratorKey();
        $entry->methodVisibility['key'] = $pub;
        $entry->methods['next'] = new ArrayIteratorNext();
        $entry->methodVisibility['next'] = $pub;
        $entry->methods['rewind'] = new ArrayIteratorRewind();
        $entry->methodVisibility['rewind'] = $pub;
        $entry->methods['seek'] = new ArrayIteratorSeek();
        $entry->methodVisibility['seek'] = $pub;
        $entry->methods['valid'] = new ArrayIteratorValid();
        $entry->methodVisibility['valid'] = $pub;
        $entry->methods['count'] = new ArrayIteratorCount();
        $entry->methodVisibility['count'] = $pub;
        $entry->methods['getarraycopy'] = new ArrayIteratorGetArrayCopy();
        $entry->methodVisibility['getarraycopy'] = $pub;
        $entry->methods['append'] = new ArrayIteratorAppend();
        $entry->methodVisibility['append'] = $pub;
        $entry->methods['offsetget'] = new ArrayIteratorOffsetGet();
        $entry->methodVisibility['offsetget'] = $pub;
        $entry->methods['offsetset'] = new ArrayIteratorOffsetSet();
        $entry->methodVisibility['offsetset'] = $pub;
        $entry->methods['offsetexists'] = new ArrayIteratorOffsetExists();
        $entry->methodVisibility['offsetexists'] = $pub;
        $entry->methods['offsetunset'] = new ArrayIteratorOffsetUnset();
        $entry->methodVisibility['offsetunset'] = $pub;
        foreach ([
            'asort' => true,
            'ksort' => true,
            'arsort' => true,
            'krsort' => true,
            'natsort' => false,
            'natcasesort' => false,
        ] as $lc => $acceptsFlags) {
            $entry->methods[$lc] = new ArrayIteratorSortMethod($lc, $acceptsFlags);
            $entry->methodVisibility[$lc] = $pub;
        }

        $ctx->classes[self::CLASS_LC] = $entry;
    }

    public static function init(ObjectEntry $object, HashTable $table): void
    {
        SplArrayStorage::init($object, $table, 0, null, []);
    }

    public static function rewind(ObjectEntry $object): void
    {
        SplArrayStorage::rewindIterator($object);
    }

    public static function next(ObjectEntry $object): void
    {
        SplArrayStorage::nextIterator($object);
    }

    public static function valid(ObjectEntry $object): bool
    {
        return SplArrayStorage::iteratorValid($object);
    }

    public static function current(ObjectEntry $object): Variable
    {
        return SplArrayStorage::iteratorCurrent($object);
    }

    public static function key(ObjectEntry $object): int|string
    {
        return SplArrayStorage::iteratorKey($object);
    }

    public static function seek(ObjectEntry $object, int $position): void
    {
        SplArrayStorage::seekIterator($object, $position);
    }

    public static function count(ObjectEntry $object): int
    {
        return SplArrayStorage::count($object);
    }
}

final class ArrayIteratorConstruct extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiver(
            $frame,
            ArrayIteratorBuiltin::CLASS_LC,
            'ArrayIterator::__construct()'
        );
        $table = new HashTable();
        if (isset($frame->calledArgs[1])) {
            $table = SplIteratorSupport::requireArrayArg(
                $frame->calledArgs[1],
                'ArrayIterator::__construct',
                1
            );
        }
        ArrayIteratorBuiltin::init($object, $table);
    }
}

final class ArrayIteratorRewind extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('rewind');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiver(
            $frame,
            ArrayIteratorBuiltin::CLASS_LC,
            'ArrayIterator::rewind()'
        );
        ArrayIteratorBuiltin::rewind($object);
    }
}

final class ArrayIteratorSeek extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('seek');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiver(
            $frame,
            ArrayIteratorBuiltin::CLASS_LC,
            'ArrayIterator::seek()'
        );
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError(
                'ArrayIterator::seek() expects exactly 1 argument, '
                .(\count($frame->calledArgs) - 1).' given'
            );
        }
        $offset = $frame->calledArgs[1]->resolveIndirect()->toInt();
        ArrayIteratorBuiltin::seek($object, $offset);
    }
}

final class ArrayIteratorNext extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('next');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiver(
            $frame,
            ArrayIteratorBuiltin::CLASS_LC,
            'ArrayIterator::next()'
        );
        ArrayIteratorBuiltin::next($object);
    }
}

final class ArrayIteratorValid extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('valid');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiver(
            $frame,
            ArrayIteratorBuiltin::CLASS_LC,
            'ArrayIterator::valid()'
        );
        SplIteratorSupport::setReturnBool($frame, ArrayIteratorBuiltin::valid($object));
    }
}

final class ArrayIteratorCurrent extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('current');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiver(
            $frame,
            ArrayIteratorBuiltin::CLASS_LC,
            'ArrayIterator::current()'
        );
        SplIteratorSupport::copyReturnFrom($frame, ArrayIteratorBuiltin::current($object));
    }
}

final class ArrayIteratorKey extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('key');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiver(
            $frame,
            ArrayIteratorBuiltin::CLASS_LC,
            'ArrayIterator::key()'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $key = ArrayIteratorBuiltin::key($object);
        if (\is_int($key)) {
            $frame->returnVar->int($key);
        } else {
            $frame->returnVar->string((string) $key);
        }
    }
}

final class ArrayIteratorCount extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('count');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiver(
            $frame,
            ArrayIteratorBuiltin::CLASS_LC,
            'ArrayIterator::count()'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int(ArrayIteratorBuiltin::count($object));
    }
}

final class ArrayIteratorGetArrayCopy extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getArrayCopy');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiver(
            $frame,
            ArrayIteratorBuiltin::CLASS_LC,
            'ArrayIterator::getArrayCopy()'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->array(SplArrayStorage::getArrayCopy($object));
    }
}

final class ArrayIteratorAppend extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('append');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiver(
            $frame,
            ArrayIteratorBuiltin::CLASS_LC,
            'ArrayIterator::append()'
        );
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError(
                'ArrayIterator::append() expects exactly 1 argument, '
                .(\count($frame->calledArgs) - 1).' given'
            );
        }
        SplArrayStorage::append($object, $frame->calledArgs[1]);
    }
}

final class ArrayIteratorOffsetGet extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('offsetGet');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiver(
            $frame,
            ArrayIteratorBuiltin::CLASS_LC,
            'ArrayIterator::offsetGet()'
        );
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError(
                'ArrayIterator::offsetGet() expects exactly 1 argument, '
                .(\count($frame->calledArgs) - 1).' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        SplIteratorSupport::copyReturnFrom(
            $frame,
            SplArrayStorage::offsetGet($object, $frame->calledArgs[1])
        );
    }
}

final class ArrayIteratorOffsetSet extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('offsetSet');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiver(
            $frame,
            ArrayIteratorBuiltin::CLASS_LC,
            'ArrayIterator::offsetSet()'
        );
        if (\count($frame->calledArgs) < 3) {
            throw new \ArgumentCountError(
                'ArrayIterator::offsetSet() expects exactly 2 arguments, '
                .(\count($frame->calledArgs) - 1).' given'
            );
        }
        SplArrayStorage::offsetSet($object, $frame->calledArgs[1], $frame->calledArgs[2]);
    }
}

final class ArrayIteratorOffsetExists extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('offsetExists');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiver(
            $frame,
            ArrayIteratorBuiltin::CLASS_LC,
            'ArrayIterator::offsetExists()'
        );
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError(
                'ArrayIterator::offsetExists() expects exactly 1 argument, '
                .(\count($frame->calledArgs) - 1).' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool(SplArrayStorage::offsetExists($object, $frame->calledArgs[1]));
    }
}

final class ArrayIteratorOffsetUnset extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('offsetUnset');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiver(
            $frame,
            ArrayIteratorBuiltin::CLASS_LC,
            'ArrayIterator::offsetUnset()'
        );
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError(
                'ArrayIterator::offsetUnset() expects exactly 1 argument, '
                .(\count($frame->calledArgs) - 1).' given'
            );
        }
        SplArrayStorage::offsetUnset($object, $frame->calledArgs[1]);
    }
}

/** @internal */
final class ArrayIteratorSortMethod extends VmClassMethod
{
    public function __construct(
        private readonly string $methodLc,
        private readonly bool $acceptsFlags,
    ) {
        parent::__construct($methodLc);
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiver(
            $frame,
            ArrayIteratorBuiltin::CLASS_LC,
            'ArrayIterator::'.$this->methodLc.'()'
        );
        $argc = \count($frame->calledArgs);
        $method = 'ArrayIterator::'.$this->methodLc.'()';
        if ($this->acceptsFlags) {
            if ($argc > 2) {
                throw new \ArgumentCountError(
                    $method.' expects at most 1 argument, '.($argc - 1).' given'
                );
            }
            $flags = StdlibConstants::SORT_REGULAR;
            if ($argc >= 2) {
                $flags = VmInternalCompare::resolveFrameSortFlags($frame, $method, 1);
            }
            SplArrayStorage::sortBacking($object, $this->methodLc, $flags);

            return;
        }
        if ($argc > 1) {
            throw new \ArgumentCountError(
                $method.' expects exactly 0 arguments, '.($argc - 1).' given'
            );
        }
        SplArrayStorage::sortBacking($object, $this->methodLc);
    }
}
