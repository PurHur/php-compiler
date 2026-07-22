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
 * RecursiveArrayIterator — extends ArrayIterator (php-src ext/spl/spl_iterators.c; #6593, #22286).
 *
 * ArrayAccess / sort / seek / flags / serialize inherit from ArrayIterator via parentLc +
 * shared {@see SplArrayStorage}. Only recursive navigation is registered here.
 */
final class RecursiveArrayIteratorBuiltin
{
    public const CLASS_LC = 'recursivearrayiterator';

    public static function registerClass(Context $ctx): void
    {
        ArrayIteratorBuiltin::registerClass($ctx);

        if (isset($ctx->classes[self::CLASS_LC]) && self::classIsComplete($ctx->classes[self::CLASS_LC])) {
            return;
        }

        $pub = CfgFunc::FLAG_PUBLIC;
        $entry = isset($ctx->classes[self::CLASS_LC])
            ? $ctx->classes[self::CLASS_LC]
            : new ClassEntry('RecursiveArrayIterator');
        $entry->parentLc = ArrayIteratorBuiltin::CLASS_LC;
        if (isset($ctx->classes['recursiveiterator'])
            && !\in_array('recursiveiterator', $entry->interfaces, true)
            && !\in_array('RecursiveIterator', $entry->interfaces, true)) {
            $entry->interfaces[] = 'recursiveiterator';
        }

        $entry->constructor = new RecursiveArrayIteratorConstruct();
        $entry->methods['__construct'] = $entry->constructor;
        $entry->methodVisibility['__construct'] = $pub;
        $entry->methods['haschildren'] = new RecursiveArrayIteratorHasChildren();
        $entry->methodVisibility['haschildren'] = $pub;
        $entry->methodNames['haschildren'] = 'hasChildren';
        $entry->methods['getchildren'] = new RecursiveArrayIteratorGetChildren();
        $entry->methodVisibility['getchildren'] = $pub;
        $entry->methodNames['getchildren'] = 'getChildren';

        $ctx->classes[self::CLASS_LC] = $entry;
    }

    private static function classIsComplete(ClassEntry $entry): bool
    {
        return isset(
            $entry->methods['__construct'],
            $entry->methods['haschildren'],
            $entry->methods['getchildren']
        ) && ArrayIteratorBuiltin::CLASS_LC === $entry->parentLc;
    }

    public static function init(ObjectEntry $object, HashTable $table, int $flags = 0): void
    {
        SplArrayStorage::init($object, $table, $flags, null, []);
    }

    /** Zend FE_RESET_RW allow-list for array-backed RecursiveArrayIterator (#19444). */
    public static function allowsForeachByRef(ObjectEntry $object): bool
    {
        return SplArrayStorage::allowsForeachByRef($object);
    }

    /** Live HashTable entry for foreach by-ref write-through (#19444). */
    public static function foreachCurrentByRef(ObjectEntry $object): Variable
    {
        return SplArrayStorage::foreachCurrentByRef($object);
    }

    public static function hasChildren(ObjectEntry $object): bool
    {
        if (!SplArrayStorage::iteratorValid($object)) {
            return false;
        }
        $current = SplArrayStorage::iteratorCurrent($object)->resolveIndirect();

        return Variable::TYPE_ARRAY === $current->type;
    }

    public static function getChildren(Context $ctx, ObjectEntry $object): Variable
    {
        if (!self::hasChildren($object)) {
            throw new \LogicException('RecursiveArrayIterator::getChildren() called on element without children');
        }
        $current = SplArrayStorage::iteratorCurrent($object)->resolveIndirect();

        return self::createFromTable($ctx, $current->toArray(), SplArrayStorage::getFlags($object));
    }

    public static function createFromTable(Context $ctx, HashTable $table, int $flags = 0): Variable
    {
        $class = $ctx->classes[self::CLASS_LC] ?? null;
        if (null === $class) {
            throw new \LogicException('RecursiveArrayIterator is not registered in this compiler build');
        }
        $object = new ObjectEntry($class);
        $object->constructed = true;
        self::init($object, $table, $flags);
        $var = new Variable(Variable::TYPE_OBJECT);
        $var->object($object);

        return $var;
    }
}

final class RecursiveArrayIteratorConstruct extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            RecursiveArrayIteratorBuiltin::CLASS_LC,
            'RecursiveArrayIterator::__construct()'
        );
        $table = new HashTable();
        if (isset($frame->calledArgs[1])) {
            $table = SplIteratorSupport::requireArrayArg(
                $frame->calledArgs[1],
                'RecursiveArrayIterator::__construct',
                1
            )->duplicate();
        }
        $flags = 0;
        if (isset($frame->calledArgs[2])) {
            $flags = $frame->calledArgs[2]->resolveIndirect()->toInt();
        }
        RecursiveArrayIteratorBuiltin::init($object, $table, $flags);
    }
}

final class RecursiveArrayIteratorHasChildren extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('hasChildren');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            RecursiveArrayIteratorBuiltin::CLASS_LC,
            'RecursiveArrayIterator::hasChildren()'
        );
        SplIteratorSupport::setReturnBool($frame, RecursiveArrayIteratorBuiltin::hasChildren($object));
    }
}

final class RecursiveArrayIteratorGetChildren extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getChildren');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            RecursiveArrayIteratorBuiltin::CLASS_LC,
            'RecursiveArrayIterator::getChildren()'
        );
        if (null === $frame->vmContext) {
            throw new \LogicException('RecursiveArrayIterator::getChildren() requires VM context');
        }
        SplIteratorSupport::copyReturnFrom(
            $frame,
            RecursiveArrayIteratorBuiltin::getChildren($frame->vmContext, $object)
        );
    }
}
