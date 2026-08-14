<?php

declare(strict_types=1);

namespace PHPCompiler\ext\spl;

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
        // php-src ext/spl/spl_array.stub.php:
        //   class ArrayIterator implements SeekableIterator, ArrayAccess, Serializable, Countable
        // Order is observable via class_implements() / ReflectionClass::getInterfaces() (#25790).
        foreach (['seekableiterator', 'arrayaccess', 'serializable', 'countable'] as $iface) {
            if (isset($ctx->classes[$iface])) {
                $entry->interfaces[] = $iface;
            }
        }

        // php-src REGISTER_SPL_CLASS_CONST_LONG — lc keys + constNames for defined()/getConstant (#22348).
        SplClassConstants::registerIntConstants($entry, [
            'STD_PROP_LIST' => 1,
            'ARRAY_AS_PROPS' => 2,
        ]);

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
        $entry->methods['getflags'] = new ArrayIteratorGetFlags();
        $entry->methodVisibility['getflags'] = $pub;
        $entry->methods['setflags'] = new ArrayIteratorSetFlags();
        $entry->methodVisibility['setflags'] = $pub;
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
        SplArrayStorage::attachArrayAccessArginfo($entry);
        // php-src ext/spl/spl_array.stub.php — ArrayIterator has asort/ksort/natsort/…
        // but not arsort/krsort (those are procedural-only / ArrayObject also omits them). #22594
        foreach ([
            'asort' => true,
            'ksort' => true,
            'natsort' => false,
            'natcasesort' => false,
        ] as $lc => $acceptsFlags) {
            $entry->methods[$lc] = new SplArraySortMethod(self::CLASS_LC, $lc, $acceptsFlags);
            $entry->methodVisibility[$lc] = $pub;
        }
        foreach (['uasort', 'uksort'] as $lc) {
            $entry->methods[$lc] = new SplArrayUserSortMethod(self::CLASS_LC, $lc);
            $entry->methodVisibility[$lc] = $pub;
        }

        SplLegacySerializableMethods::register($entry, self::CLASS_LC, 'ArrayIterator');
        SplArraySerializeSupport::registerMagicMethods($entry, self::CLASS_LC, 'ArrayIterator', $pub);

        $entry->methods['__debuginfo'] = new ArrayIteratorDebugInfo();
        $entry->methodVisibility['__debuginfo'] = $pub;
        $entry->methodNames['__debuginfo'] = '__debugInfo';
        $entry->cloneObjectHandler = [SplArrayStorage::class, 'cloneInto'];

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

    public static function key(ObjectEntry $object): int|string|null
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

/**
 * ArrayIterator::__debugInfo() — private storage bag + dynamic props (php-src spl_array_object_debug_info; #19782).
 */
final class ArrayIteratorDebugInfo extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__debugInfo');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            ArrayIteratorBuiltin::CLASS_LC,
            'ArrayIterator::__debugInfo()'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $ht = new HashTable();
        foreach ($object->getRawProperties() as $name => $prop) {
            $copy = new Variable();
            $copy->copyFrom($prop->resolveIndirect());
            $ht->addNew((string) $name, $copy);
        }
        $storage = new Variable();
        $storage->array(SplArrayStorage::getArrayCopy($object));
        $ht->addNew("\0ArrayIterator\0storage", $storage);
        $frame->returnVar->array($ht);
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
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            ArrayIteratorBuiltin::CLASS_LC,
            'ArrayIterator::__construct()'
        );
        $table = new HashTable();
        $flags = 0;
        $hasFlagsArg = isset($frame->calledArgs[2]);
        if ($hasFlagsArg) {
            $flags = $frame->calledArgs[2]->resolveIndirect()->toInt();
        }
        if (isset($frame->calledArgs[1])) {
            // php-src spl_array_set_array — array copy (#22020); object|ArrayObject share (#23886).
            // just_array=(ZEND_NUM_ARGS()==1): inherit flags from ArrayObject when flags omitted.
            [$table, $flags] = SplIteratorSupport::requireArrayOrObjectConstructArg(
                $frame->calledArgs[1],
                'ArrayIterator::__construct',
                1,
                $flags,
                !$hasFlagsArg
            );
        }
        SplArrayStorage::init($object, $table, $flags);
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
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            ArrayIteratorBuiltin::CLASS_LC,
            'ArrayIterator::rewind()'
        );
        // php-src: ZEND_PARSE_PARAMETERS_NONE (#30911)
        $this->requireExactUserArgCount($frame, 'ArrayIterator::rewind', 0);
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
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            ArrayIteratorBuiltin::CLASS_LC,
            'ArrayIterator::seek()'
        );
        // php-src zim_ArrayIterator_seek — exactly 1 user arg (#30963)
        $this->requireExactUserArgCount($frame, 'ArrayIterator::seek', 1);
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
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            ArrayIteratorBuiltin::CLASS_LC,
            'ArrayIterator::next()'
        );
        // php-src: ZEND_PARSE_PARAMETERS_NONE (#30911)
        $this->requireExactUserArgCount($frame, 'ArrayIterator::next', 0);
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
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            ArrayIteratorBuiltin::CLASS_LC,
            'ArrayIterator::valid()'
        );
        // php-src: ZEND_PARSE_PARAMETERS_NONE (#30911)
        $this->requireExactUserArgCount($frame, 'ArrayIterator::valid', 0);
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
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            ArrayIteratorBuiltin::CLASS_LC,
            'ArrayIterator::current()'
        );
        // php-src: ZEND_PARSE_PARAMETERS_NONE (#30911)
        $this->requireExactUserArgCount($frame, 'ArrayIterator::current', 0);
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
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            ArrayIteratorBuiltin::CLASS_LC,
            'ArrayIterator::key()'
        );
        // php-src: ZEND_PARSE_PARAMETERS_NONE (#30911)
        $this->requireExactUserArgCount($frame, 'ArrayIterator::key', 0);
        if (null === $frame->returnVar) {
            return;
        }
        $key = ArrayIteratorBuiltin::key($object);
        if (null === $key) {
            $frame->returnVar->null();
        } elseif (\is_int($key)) {
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
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            ArrayIteratorBuiltin::CLASS_LC,
            'ArrayIterator::count()'
        );
        // php-src: ZEND_PARSE_PARAMETERS_NONE — ArrayIterator::count() (#20162, #30911)
        $this->requireExactUserArgCount($frame, 'ArrayIterator::count', 0);
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
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            ArrayIteratorBuiltin::CLASS_LC,
            'ArrayIterator::getArrayCopy()'
        );
        // php-src zim_ArrayIterator_getArrayCopy — ZEND_PARSE_PARAMETERS_NONE (#30963)
        $this->requireExactUserArgCount($frame, 'ArrayIterator::getArrayCopy', 0);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->array(SplArrayStorage::getArrayCopy($object));
    }
}

final class ArrayIteratorGetFlags extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getFlags');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            ArrayIteratorBuiltin::CLASS_LC,
            'ArrayIterator::getFlags()'
        );
        // php-src zim_ArrayIterator_getFlags — ZEND_PARSE_PARAMETERS_NONE (#30963)
        $this->requireExactUserArgCount($frame, 'ArrayIterator::getFlags', 0);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int(SplArrayStorage::getFlags($object));
    }
}

final class ArrayIteratorSetFlags extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('setFlags');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            ArrayIteratorBuiltin::CLASS_LC,
            'ArrayIterator::setFlags()'
        );
        // php-src zim_ArrayIterator_setFlags — exactly 1 user arg (#30963)
        $this->requireExactUserArgCount($frame, 'ArrayIterator::setFlags', 1);
        $flags = $frame->calledArgs[1]->resolveIndirect()->toInt();
        SplArrayStorage::setFlags($object, $flags);
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
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            ArrayIteratorBuiltin::CLASS_LC,
            'ArrayIterator::append()'
        );
        // php-src zim_ArrayIterator_append — exactly 1 user arg (#30963)
        $this->requireExactUserArgCount($frame, 'ArrayIterator::append', 1);
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
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            ArrayIteratorBuiltin::CLASS_LC,
            'ArrayIterator::offsetGet()'
        );
        // php-src zim_ArrayIterator_offsetGet — exactly 1 user arg (#30963)
        $this->requireExactUserArgCount($frame, 'ArrayIterator::offsetGet', 1);
        if (null === $frame->returnVar) {
            return;
        }
        SplIteratorSupport::copyReturnFrom(
            $frame,
            SplArrayStorage::offsetGet($object, $frame->calledArgs[1], $frame)
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
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            ArrayIteratorBuiltin::CLASS_LC,
            'ArrayIterator::offsetSet()'
        );
        // php-src zim_ArrayIterator_offsetSet — exactly 2 user args (#30963)
        $this->requireExactUserArgCount($frame, 'ArrayIterator::offsetSet', 2);
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
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            ArrayIteratorBuiltin::CLASS_LC,
            'ArrayIterator::offsetExists()'
        );
        // php-src zim_ArrayIterator_offsetExists — exactly 1 user arg (#30963)
        $this->requireExactUserArgCount($frame, 'ArrayIterator::offsetExists', 1);
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
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            ArrayIteratorBuiltin::CLASS_LC,
            'ArrayIterator::offsetUnset()'
        );
        // php-src zim_ArrayIterator_offsetUnset — exactly 1 user arg (#30963)
        $this->requireExactUserArgCount($frame, 'ArrayIterator::offsetUnset', 1);
        SplArrayStorage::offsetUnset($object, $frame->calledArgs[1]);
    }
}
