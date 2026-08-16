<?php

declare(strict_types=1);

namespace PHPCompiler\ext\spl;

use PHPCompiler\ext\standard\VmMath;
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
        // php-src ext/spl/spl_array.stub.php:
        //   class ArrayObject implements IteratorAggregate, ArrayAccess, Serializable, Countable
        // Order is observable via class_implements() / ReflectionClass::getInterfaces() (#25315, #25327).
        foreach (['iteratoraggregate', 'arrayaccess', 'serializable', 'countable'] as $iface) {
            if (isset($ctx->classes[$iface])) {
                $entry->interfaces[] = $iface;
            }
        }

        // php-src REGISTER_SPL_CLASS_CONST_LONG — lc keys + constNames for defined()/getConstant (#22348).
        SplClassConstants::registerIntConstants($entry, [
            'STD_PROP_LIST' => 1,
            'ARRAY_AS_PROPS' => 2,
        ]);

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
        SplArrayStorage::attachArrayAccessArginfo($entry);
        $entry->methods['append'] = new ArrayObjectAppend();
        $entry->methodVisibility['append'] = $pub;
        $entry->methods['exchangearray'] = new ArrayObjectExchangeArray();
        $entry->methodVisibility['exchangearray'] = $pub;
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

        SplLegacySerializableMethods::register($entry, self::CLASS_LC, 'ArrayObject');
        SplArraySerializeSupport::registerMagicMethods($entry, self::CLASS_LC, 'ArrayObject', $pub);

        $entry->methods['__debuginfo'] = new ArrayObjectDebugInfo();
        $entry->methodVisibility['__debuginfo'] = $pub;
        $entry->methodNames['__debuginfo'] = '__debugInfo';
        $entry->cloneObjectHandler = [SplArrayStorage::class, 'cloneInto'];

        $ctx->classes[self::CLASS_LC] = $entry;
    }
}

/**
 * ArrayObject::__debugInfo() — private storage bag + dynamic props (php-src spl_array_object_debug_info; #19764).
 */
final class ArrayObjectDebugInfo extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__debugInfo');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            ArrayObjectBuiltin::CLASS_LC,
            'ArrayObject::__debugInfo()'
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
        $ht->addNew("\0ArrayObject\0storage", $storage);
        $frame->returnVar->array($ht);
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
        // php-src zim_ArrayObject___construct — array|object + optional flags/iterator class (#31071, #31539).
        $this->requireAtMostUserArgCount($frame, 'ArrayObject::__construct', 3);
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            ArrayObjectBuiltin::CLASS_LC,
            'ArrayObject::__construct()'
        );
        $table = new HashTable();
        $flags = 0;
        $hasFlagsArg = isset($frame->calledArgs[2]);
        if ($hasFlagsArg) {
            // php-src Z_PARAM_LONG $flags — soft-null DEP+0 outside strict_types (#31648).
            $flags = VmMath::parseZParamLongBuiltinArgForFrame(
                $frame,
                2,
                'ArrayObject::__construct',
                2,
                'flags'
            );
        }
        if (isset($frame->calledArgs[1])) {
            // php-src spl_array_set_array — array copy; ArrayObject/object share (#31539 / #23886).
            // just_array=(ZEND_NUM_ARGS()==1): inherit flags from ArrayObject when flags omitted.
            [$table, $flags] = SplIteratorSupport::requireArrayOrObjectConstructArg(
                $frame->calledArgs[1],
                'ArrayObject::__construct',
                1,
                $flags,
                !$hasFlagsArg
            );
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
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            ArrayObjectBuiltin::CLASS_LC,
            'ArrayObject::getArrayCopy()'
        );
        // php-src ZEND_PARSE_PARAMETERS_NONE (#30965).
        $this->requireExactUserArgCount($frame, 'ArrayObject::getArrayCopy', 0);
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
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            ArrayObjectBuiltin::CLASS_LC,
            'ArrayObject::count()'
        );
        // php-src: ZEND_PARSE_PARAMETERS_NONE — ArrayObject::count() (#20162)
        if (\count($frame->calledArgs) > 1) {
            throw new \ArgumentCountError(
                'ArrayObject::count() expects exactly 0 arguments, '
                .(\count($frame->calledArgs) - 1).' given'
            );
        }
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
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            ArrayObjectBuiltin::CLASS_LC,
            'ArrayObject::getIteratorClass()'
        );
        // php-src ZEND_PARSE_PARAMETERS_NONE (#30965).
        $this->requireExactUserArgCount($frame, 'ArrayObject::getIteratorClass', 0);
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
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            ArrayObjectBuiltin::CLASS_LC,
            'ArrayObject::setIteratorClass()'
        );
        // php-src zim_ArrayObject_setIteratorClass — exactly 1 user arg (#30965).
        $this->requireExactUserArgCount($frame, 'ArrayObject::setIteratorClass', 1);
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
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            ArrayObjectBuiltin::CLASS_LC,
            'ArrayObject::getFlags()'
        );
        // php-src ZEND_PARSE_PARAMETERS_NONE (#30965).
        $this->requireExactUserArgCount($frame, 'ArrayObject::getFlags', 0);
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
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            ArrayObjectBuiltin::CLASS_LC,
            'ArrayObject::setFlags()'
        );
        // php-src zim_ArrayObject_setFlags — exactly 1 user arg (#30965).
        $this->requireExactUserArgCount($frame, 'ArrayObject::setFlags', 1);
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
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            ArrayObjectBuiltin::CLASS_LC,
            'ArrayObject::getIterator()'
        );
        // User arity excludes $this (#30837; ZEND_PARSE_PARAMETERS_NONE).
        $this->requireExactUserArgCount($frame, 'ArrayObject::getIterator', 0);
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
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            ArrayObjectBuiltin::CLASS_LC,
            'ArrayObject::offsetGet()'
        );
        // php-src zim_ArrayObject_offsetGet — ZEND_PARSE_PARAMETERS_ARGS(1, 1) (#31001)
        $this->requireExactUserArgCount($frame, 'ArrayObject::offsetGet', 1);
        if (null === $frame->returnVar) {
            return;
        }
        SplIteratorSupport::copyReturnFrom(
            $frame,
            SplArrayStorage::offsetGet($object, $frame->calledArgs[1], $frame)
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
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            ArrayObjectBuiltin::CLASS_LC,
            'ArrayObject::offsetSet()'
        );
        // php-src zim_ArrayObject_offsetSet — ZEND_PARSE_PARAMETERS_ARGS(2, 2) (#31001)
        $this->requireExactUserArgCount($frame, 'ArrayObject::offsetSet', 2);
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
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            ArrayObjectBuiltin::CLASS_LC,
            'ArrayObject::offsetExists()'
        );
        // php-src zim_ArrayObject_offsetExists — ZEND_PARSE_PARAMETERS_ARGS(1, 1) (#31001)
        $this->requireExactUserArgCount($frame, 'ArrayObject::offsetExists', 1);
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
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            ArrayObjectBuiltin::CLASS_LC,
            'ArrayObject::offsetUnset()'
        );
        // php-src zim_ArrayObject_offsetUnset — ZEND_PARSE_PARAMETERS_ARGS(1, 1) (#31001)
        $this->requireExactUserArgCount($frame, 'ArrayObject::offsetUnset', 1);
        SplArrayStorage::offsetUnset($object, $frame->calledArgs[1]);
    }
}

final class ArrayObjectExchangeArray extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('exchangeArray');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            ArrayObjectBuiltin::CLASS_LC,
            'ArrayObject::exchangeArray()'
        );
        // User arity excludes $this (#30837; zim_ArrayObject_exchangeArray).
        $this->requireExactUserArgCount($frame, 'ArrayObject::exchangeArray', 1);
        // php-src Z_PARAM_ARRAY_OR_OBJECT ("A") + spl_array_set_array(just_array=true) (#31528).
        // ArrayObject/ArrayIterator → share live table; plain objects → property HT; arrays → copy.
        [$table, $flags] = SplIteratorSupport::requireArrayOrObjectConstructArg(
            $frame->calledArgs[1],
            'ArrayObject::exchangeArray',
            1,
            0,
            true
        );
        if (null === $frame->returnVar) {
            SplArrayStorage::exchangeArray($object, $table, $flags);

            return;
        }
        $old = SplArrayStorage::exchangeArray($object, $table, $flags);
        $frame->returnVar->array($old);
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
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            ArrayObjectBuiltin::CLASS_LC,
            'ArrayObject::append()'
        );
        // User arity excludes $this (#30837; zim_ArrayObject_append).
        $this->requireExactUserArgCount($frame, 'ArrayObject::append', 1);
        SplArrayStorage::append($object, $frame->calledArgs[1]);
    }
}
