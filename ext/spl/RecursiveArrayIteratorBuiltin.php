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

    /** php-src SPL_ARRAY_CHILD_ARRAYS_ONLY (ext/spl/spl_array.h) — #22321. */
    public const CHILD_ARRAYS_ONLY = 4;

    public static function registerClass(Context $ctx): void
    {
        ArrayIteratorBuiltin::registerClass($ctx);

        $pub = CfgFunc::FLAG_PUBLIC;
        $entry = isset($ctx->classes[self::CLASS_LC])
            ? $ctx->classes[self::CLASS_LC]
            : new ClassEntry('RecursiveArrayIterator');
        $entry->parentLc = ArrayIteratorBuiltin::CLASS_LC;
        // Zend rematerializes the full flattened ce->interfaces table on the subclass
        // (not parent ArrayIterator order + RecursiveIterator). Observable via
        // class_implements() / ReflectionClass::getInterfaceNames() (#25796).
        // php-src ext/spl/spl_array.c — RecursiveArrayIterator class entry.
        $entry->interfaces = [];
        foreach ([
            'countable',
            'serializable',
            'arrayaccess',
            'iterator',
            'traversable',
            'seekableiterator',
            'recursiveiterator',
        ] as $iface) {
            if (isset($ctx->classes[$iface])) {
                $entry->interfaces[] = $iface;
            }
        }

        // php-src REGISTER_SPL_CLASS_CONST_LONG CHILD_ARRAYS_ONLY (#22321).
        SplClassConstants::registerIntConstants($entry, [
            'CHILD_ARRAYS_ONLY' => self::CHILD_ARRAYS_ONLY,
        ]);

        if (self::classIsComplete($entry)) {
            $ctx->classes[self::CLASS_LC] = $entry;

            return;
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

    /**
     * php-src RecursiveArrayIterator::hasChildren — array always; object unless CHILD_ARRAYS_ONLY (#22321).
     */
    public static function hasChildren(ObjectEntry $object): bool
    {
        if (!SplArrayStorage::iteratorValid($object)) {
            return false;
        }
        $current = SplArrayStorage::iteratorCurrent($object)->resolveIndirect();
        if (Variable::TYPE_ARRAY === $current->type) {
            return true;
        }
        if (Variable::TYPE_OBJECT === $current->type) {
            return 0 === (SplArrayStorage::getFlags($object) & self::CHILD_ARRAYS_ONLY);
        }

        return false;
    }

    public static function getChildren(Context $ctx, ObjectEntry $object): Variable
    {
        if (!self::hasChildren($object)) {
            throw new \LogicException('RecursiveArrayIterator::getChildren() called on element without children');
        }
        $current = SplArrayStorage::iteratorCurrent($object)->resolveIndirect();
        $flags = SplArrayStorage::getFlags($object);

        if (Variable::TYPE_OBJECT === $current->type) {
            $childObj = $current->toObject();
            // php-src: instanceof RecursiveArrayIterator → RETURN_OBJ_COPY
            if (self::objectIsA($ctx, $childObj, self::CLASS_LC)) {
                $var = new Variable(Variable::TYPE_OBJECT);
                $var->object($childObj);

                return $var;
            }

            // Child object: copy storage snapshot for the new RecursiveArrayIterator (#22321).
            if (SplArrayStorage::hasState($childObj)) {
                return self::createFromTable($ctx, SplArrayStorage::getArrayCopy($childObj), $flags);
            }

            return self::createFromTable(
                $ctx,
                SplArrayStorage::hashTableFromObjectProperties($childObj),
                $flags
            );
        }

        return self::createFromTable($ctx, $current->toArray(), $flags);
    }

    private static function objectIsA(Context $ctx, ObjectEntry $object, string $rootClassLc): bool
    {
        $entry = $object->class;
        while (true) {
            if (strtolower(ltrim($entry->name, '\\')) === $rootClassLc) {
                return true;
            }
            $parentLc = $entry->parentLc;
            if (null === $parentLc) {
                return false;
            }
            if (!isset($ctx->classes[$parentLc])) {
                return $parentLc === $rootClassLc;
            }
            $entry = $ctx->classes[$parentLc];
        }
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
        $flags = 0;
        $hasFlagsArg = isset($frame->calledArgs[2]);
        if ($hasFlagsArg) {
            $flags = $frame->calledArgs[2]->resolveIndirect()->toInt();
        }
        if (isset($frame->calledArgs[1])) {
            // php-src spl_array_set_array — array|object (#23886); just_array when flags omitted.
            [$table, $flags] = SplIteratorSupport::requireArrayOrObjectConstructArg(
                $frame->calledArgs[1],
                'RecursiveArrayIterator::__construct',
                1,
                $flags,
                !$hasFlagsArg
            );
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
        // php-src zim_RecursiveArrayIterator_hasChildren — ZEND_PARSE_PARAMETERS_NONE (#30963)
        $this->requireExactUserArgCount($frame, 'RecursiveArrayIterator::hasChildren', 0);
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
        // php-src zim_RecursiveArrayIterator_getChildren — ZEND_PARSE_PARAMETERS_NONE (#31042)
        $this->requireExactUserArgCount($frame, 'RecursiveArrayIterator::getChildren', 0);
        if (null === $frame->vmContext) {
            throw new \LogicException('RecursiveArrayIterator::getChildren() requires VM context');
        }
        SplIteratorSupport::copyReturnFrom(
            $frame,
            RecursiveArrayIteratorBuiltin::getChildren($frame->vmContext, $object)
        );
    }
}
