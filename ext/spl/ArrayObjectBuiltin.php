<?php

declare(strict_types=1);

namespace PHPCompiler\ext\spl;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPCompiler\VM\InterfaceCheck;
use PHPCfg\Func as CfgFunc;

/**
 * ArrayObject — array-as-object SPL builtin (php-src ext/spl/spl_array.c; #10711).
 */
final class ArrayObjectBuiltin
{
    public const CLASS_LC = 'arrayobject';

    public static function registerClass(Context $ctx): void
    {
        if (isset($ctx->classes[self::CLASS_LC])) {
            return;
        }

        $pub = CfgFunc::FLAG_PUBLIC;
        $entry = new ClassEntry('ArrayObject');
        if (isset($ctx->classes['iteratoraggregate'])) {
            $entry->interfaces[] = 'iteratoraggregate';
        }
        if (isset($ctx->classes['countable'])) {
            $entry->interfaces[] = 'countable';
        }
        if (isset($ctx->classes['arrayaccess'])) {
            $entry->interfaces[] = 'arrayaccess';
        }

        foreach ([
            'STD_PROP_LIST' => 1,
            'ARRAY_AS_PROPS' => 2,
        ] as $name => $value) {
            $const = new \PHPCompiler\VM\Variable(\PHPCompiler\VM\Variable::TYPE_INTEGER);
            $const->int($value);
            $entry->constants[$name] = $const;
        }

        $entry->constructor = new ArrayObjectConstruct();
        $entry->methods['__construct'] = $entry->constructor;
        $entry->methodVisibility['__construct'] = $pub;
        $entry->methods['getarraycopy'] = new ArrayObjectGetArrayCopy();
        $entry->methodVisibility['getarraycopy'] = $pub;
        $entry->methods['count'] = new ArrayObjectCount();
        $entry->methodVisibility['count'] = $pub;
        $entry->methods['getiteratorclass'] = new ArrayObjectGetIteratorClass();
        $entry->methodVisibility['getiteratorclass'] = $pub;
        $entry->methods['setiteratorclass'] = new ArrayObjectSetIteratorClass();
        $entry->methodVisibility['setiteratorclass'] = $pub;
        $entry->methods['getflags'] = new ArrayObjectGetFlags();
        $entry->methodVisibility['getflags'] = $pub;
        $entry->methods['setflags'] = new ArrayObjectSetFlags();
        $entry->methodVisibility['setflags'] = $pub;
        $entry->methods['getiterator'] = new ArrayObjectGetIterator();
        $entry->methodVisibility['getiterator'] = $pub;
        $entry->methods['offsetget'] = new ArrayObjectOffsetGet();
        $entry->methodVisibility['offsetget'] = $pub;
        $entry->methods['offsetset'] = new ArrayObjectOffsetSet();
        $entry->methodVisibility['offsetset'] = $pub;
        $entry->methods['offsetexists'] = new ArrayObjectOffsetExists();
        $entry->methodVisibility['offsetexists'] = $pub;
        $entry->methods['offsetunset'] = new ArrayObjectOffsetUnset();
        $entry->methodVisibility['offsetunset'] = $pub;
        $entry->methods['append'] = new ArrayObjectAppend();
        $entry->methodVisibility['append'] = $pub;

        $ctx->classes[self::CLASS_LC] = $entry;
    }
}

final class ArrayObjectConstruct extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiver(
            $frame,
            ArrayObjectBuiltin::CLASS_LC,
            'ArrayObject::__construct()'
        );
        $table = new HashTable();
        if (isset($frame->calledArgs[1])) {
            $table = SplIteratorSupport::requireArrayArg(
                $frame->calledArgs[1],
                'ArrayObject::__construct',
                1
            )->duplicate();
        }
        $flags = 0;
        if (isset($frame->calledArgs[2])) {
            $flagsVar = $frame->calledArgs[2]->resolveIndirect();
            $flags = $flagsVar->toInt();
        }
        $iteratorClass = null;
        if (isset($frame->calledArgs[3])) {
            $iterVar = $frame->calledArgs[3]->resolveIndirect();
            if (\PHPCompiler\VM\Variable::TYPE_STRING === $iterVar->type) {
                $iteratorClass = $iterVar->toString();
            }
        }
        SplArrayStorage::init($object, $table, $flags, $iteratorClass);
    }
}

final class ArrayObjectGetArrayCopy extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getArrayCopy');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiver(
            $frame,
            ArrayObjectBuiltin::CLASS_LC,
            'ArrayObject::getArrayCopy()'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->array(SplArrayStorage::getArrayCopy($object));
    }
}

final class ArrayObjectCount extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('count');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiver(
            $frame,
            ArrayObjectBuiltin::CLASS_LC,
            'ArrayObject::count()'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int(SplArrayStorage::count($object));
    }
}

final class ArrayObjectGetIteratorClass extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getIteratorClass');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiver(
            $frame,
            ArrayObjectBuiltin::CLASS_LC,
            'ArrayObject::getIteratorClass()'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->string(SplArrayStorage::getIteratorClass($object));
    }
}

final class ArrayObjectSetIteratorClass extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('setIteratorClass');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiver(
            $frame,
            ArrayObjectBuiltin::CLASS_LC,
            'ArrayObject::setIteratorClass()'
        );
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError(
                'ArrayObject::setIteratorClass() expects exactly 1 argument, '
                .(\count($frame->calledArgs) - 1).' given'
            );
        }
        $iteratorClass = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[1],
            'ArrayObject::setIteratorClass',
            0,
            'iteratorClass'
        );
        $entry = VmReflection::resolveClassEntry($frame->vmContext, $iteratorClass);
        if (null === $entry
            || !InterfaceCheck::entryIsInstanceOf($entry, ArrayIteratorBuiltin::CLASS_LC, $frame->vmContext)) {
            throw new \TypeError(
                'ArrayObject::setIteratorClass(): Argument #1 ($iteratorClass) must be a class name derived from ArrayIterator, '
                .$iteratorClass.' given'
            );
        }
        SplArrayStorage::setIteratorClass($object, $entry->name);
    }
}

final class ArrayObjectGetFlags extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getFlags');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiver(
            $frame,
            ArrayObjectBuiltin::CLASS_LC,
            'ArrayObject::getFlags()'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int(SplArrayStorage::getFlags($object));
    }
}

final class ArrayObjectSetFlags extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('setFlags');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiver(
            $frame,
            ArrayObjectBuiltin::CLASS_LC,
            'ArrayObject::setFlags()'
        );
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError(
                'ArrayObject::setFlags() expects exactly 1 argument, '
                .(\count($frame->calledArgs) - 1).' given'
            );
        }
        $flags = $frame->calledArgs[1]->resolveIndirect()->toInt();
        SplArrayStorage::setFlags($object, $flags);
    }
}

final class ArrayObjectGetIterator extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getIterator');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiver(
            $frame,
            ArrayObjectBuiltin::CLASS_LC,
            'ArrayObject::getIterator()'
        );
        if (null === $frame->returnVar) {
            return;
        }
        SplIteratorSupport::copyReturnFrom(
            $frame,
            SplArrayStorage::createIterator($frame->vmContext, $object)
        );
    }
}

final class ArrayObjectOffsetGet extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('offsetGet');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiver(
            $frame,
            ArrayObjectBuiltin::CLASS_LC,
            'ArrayObject::offsetGet()'
        );
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError(
                'ArrayObject::offsetGet() expects exactly 1 argument, '
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

final class ArrayObjectOffsetSet extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('offsetSet');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiver(
            $frame,
            ArrayObjectBuiltin::CLASS_LC,
            'ArrayObject::offsetSet()'
        );
        if (\count($frame->calledArgs) < 3) {
            throw new \ArgumentCountError(
                'ArrayObject::offsetSet() expects exactly 2 arguments, '
                .(\count($frame->calledArgs) - 1).' given'
            );
        }
        SplArrayStorage::offsetSet($object, $frame->calledArgs[1], $frame->calledArgs[2]);
    }
}

final class ArrayObjectOffsetExists extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('offsetExists');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiver(
            $frame,
            ArrayObjectBuiltin::CLASS_LC,
            'ArrayObject::offsetExists()'
        );
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError(
                'ArrayObject::offsetExists() expects exactly 1 argument, '
                .(\count($frame->calledArgs) - 1).' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool(SplArrayStorage::offsetExists($object, $frame->calledArgs[1]));
    }
}

final class ArrayObjectOffsetUnset extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('offsetUnset');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiver(
            $frame,
            ArrayObjectBuiltin::CLASS_LC,
            'ArrayObject::offsetUnset()'
        );
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError(
                'ArrayObject::offsetUnset() expects exactly 1 argument, '
                .(\count($frame->calledArgs) - 1).' given'
            );
        }
        SplArrayStorage::offsetUnset($object, $frame->calledArgs[1]);
    }
}

final class ArrayObjectAppend extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('append');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiver(
            $frame,
            ArrayObjectBuiltin::CLASS_LC,
            'ArrayObject::append()'
        );
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError(
                'ArrayObject::append() expects exactly 1 argument, '
                .(\count($frame->calledArgs) - 1).' given'
            );
        }
        SplArrayStorage::append($object, $frame->calledArgs[1]);
    }
}
