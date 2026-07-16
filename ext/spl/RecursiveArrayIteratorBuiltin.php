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
 * RecursiveArrayIterator — array tree iterator (php-src ext/spl/spl_iterators.c; #6593).
 */
final class RecursiveArrayIteratorBuiltin
{
    public const CLASS_LC = 'recursivearrayiterator';

    /** @var array<int, array> */
    private static array $store = [];

    public static function registerClass(Context $ctx): void
    {
        if (isset($ctx->classes[self::CLASS_LC])) {
            return;
        }

        $pub = CfgFunc::FLAG_PUBLIC;
        $entry = new ClassEntry('RecursiveArrayIterator');
        if (isset($ctx->classes['iterator'])) {
            $entry->interfaces[] = 'iterator';
        }
        if (isset($ctx->classes['recursiveiterator'])) {
            $entry->interfaces[] = 'recursiveiterator';
        }
        if (isset($ctx->classes['arrayaccess'])) {
            $entry->interfaces[] = 'arrayaccess';
        }

        $entry->constructor = new RecursiveArrayIteratorConstruct();
        $entry->methods['__construct'] = $entry->constructor;
        $entry->methodVisibility['__construct'] = $pub;
        $entry->methods['current'] = new RecursiveArrayIteratorCurrent();
        $entry->methodVisibility['current'] = $pub;
        $entry->methods['key'] = new RecursiveArrayIteratorKey();
        $entry->methodVisibility['key'] = $pub;
        $entry->methods['next'] = new RecursiveArrayIteratorNext();
        $entry->methodVisibility['next'] = $pub;
        $entry->methods['rewind'] = new RecursiveArrayIteratorRewind();
        $entry->methodVisibility['rewind'] = $pub;
        $entry->methods['valid'] = new RecursiveArrayIteratorValid();
        $entry->methodVisibility['valid'] = $pub;
        $entry->methods['haschildren'] = new RecursiveArrayIteratorHasChildren();
        $entry->methodVisibility['haschildren'] = $pub;
        $entry->methodNames['haschildren'] = 'hasChildren';
        $entry->methods['getchildren'] = new RecursiveArrayIteratorGetChildren();
        $entry->methodVisibility['getchildren'] = $pub;
        $entry->methodNames['getchildren'] = 'getChildren';

        $ctx->classes[self::CLASS_LC] = $entry;
    }

    public static function init(ObjectEntry $object, HashTable $table): void
    {
        $keys = [];
        foreach ($table->iterateKeyed(true) as [$keyVar, $_]) {
            $keys[] = Variable::TYPE_INTEGER === $keyVar->type
                ? $keyVar->toInt()
                : $keyVar->toString();
        }
        self::$store[$object->id] = [
            'keys' => $keys,
            'table' => $table,
            'pos' => 0,
        ];
    }

    public static function rewind(ObjectEntry $object): void
    {
        self::$store[$object->id]['pos'] = 0;
    }

    public static function next(ObjectEntry $object): void
    {
        ++self::$store[$object->id]['pos'];
    }

    public static function valid(ObjectEntry $object): bool
    {
        $state = self::state($object);

        return $state['pos'] >= 0 && $state['pos'] < \count($state['keys']);
    }

    public static function current(ObjectEntry $object): Variable
    {
        $state = self::state($object);
        if (!self::valid($object)) {
            throw new \RuntimeException('Cannot fetch current() on invalid RecursiveArrayIterator position');
        }
        $key = $state['keys'][$state['pos']];
        if (\is_int($key)) {
            $var = $state['table']->findIndex($key);
        } else {
            $var = $state['table']->find((string) $key);
        }
        if (null === $var) {
            throw new \LogicException('RecursiveArrayIterator current key missing from backing array');
        }

        return $var;
    }

    /** Zend FE_RESET_RW allow-list for array-backed RecursiveArrayIterator (#19444). */
    public static function allowsForeachByRef(ObjectEntry $object): bool
    {
        return isset(self::$store[$object->id]);
    }

    /** Live HashTable entry for foreach by-ref write-through (#19444). */
    public static function foreachCurrentByRef(ObjectEntry $object): Variable
    {
        return self::current($object);
    }

    public static function key(ObjectEntry $object): int|string
    {
        $state = self::state($object);
        if (!self::valid($object)) {
            throw new \RuntimeException('Cannot fetch key() on invalid RecursiveArrayIterator position');
        }

        return $state['keys'][$state['pos']];
    }

    public static function hasChildren(ObjectEntry $object): bool
    {
        if (!self::valid($object)) {
            return false;
        }
        $current = self::current($object)->resolveIndirect();

        return Variable::TYPE_ARRAY === $current->type;
    }

    public static function getChildren(Context $ctx, ObjectEntry $object): Variable
    {
        if (!self::hasChildren($object)) {
            throw new \LogicException('RecursiveArrayIterator::getChildren() called on element without children');
        }
        $current = self::current($object)->resolveIndirect();

        return self::createFromTable($ctx, $current->toArray());
    }

    public static function createFromTable(Context $ctx, HashTable $table): Variable
    {
        $class = $ctx->classes[self::CLASS_LC] ?? null;
        if (null === $class) {
            throw new \LogicException('RecursiveArrayIterator is not registered in this compiler build');
        }
        $object = new ObjectEntry($class);
        $object->constructed = true;
        self::init($object, $table);
        $var = new Variable(Variable::TYPE_OBJECT);
        $var->object($object);

        return $var;
    }

    /**
     * @return array
     */
    private static function state(ObjectEntry $object): array
    {
        if (!isset(self::$store[$object->id])) {
            throw new \LogicException('RecursiveArrayIterator state missing');
        }

        return self::$store[$object->id];
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
        if (\count($frame->calledArgs) < 2) {
            throw new \LogicException('RecursiveArrayIterator::__construct() expects at least 1 argument');
        }
        $object = SplIteratorSupport::receiver(
            $frame,
            RecursiveArrayIteratorBuiltin::CLASS_LC,
            'RecursiveArrayIterator::__construct()'
        );
        $table = SplIteratorSupport::requireArrayArg(
            $frame->calledArgs[1],
            'RecursiveArrayIterator::__construct',
            1
        );
        RecursiveArrayIteratorBuiltin::init($object, $table);
    }
}

final class RecursiveArrayIteratorRewind extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('rewind');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiver(
            $frame,
            RecursiveArrayIteratorBuiltin::CLASS_LC,
            'RecursiveArrayIterator::rewind()'
        );
        RecursiveArrayIteratorBuiltin::rewind($object);
    }
}

final class RecursiveArrayIteratorNext extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('next');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiver(
            $frame,
            RecursiveArrayIteratorBuiltin::CLASS_LC,
            'RecursiveArrayIterator::next()'
        );
        RecursiveArrayIteratorBuiltin::next($object);
    }
}

final class RecursiveArrayIteratorValid extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('valid');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiver(
            $frame,
            RecursiveArrayIteratorBuiltin::CLASS_LC,
            'RecursiveArrayIterator::valid()'
        );
        SplIteratorSupport::setReturnBool($frame, RecursiveArrayIteratorBuiltin::valid($object));
    }
}

final class RecursiveArrayIteratorCurrent extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('current');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiver(
            $frame,
            RecursiveArrayIteratorBuiltin::CLASS_LC,
            'RecursiveArrayIterator::current()'
        );
        SplIteratorSupport::copyReturnFrom($frame, RecursiveArrayIteratorBuiltin::current($object));
    }
}

final class RecursiveArrayIteratorKey extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('key');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiver(
            $frame,
            RecursiveArrayIteratorBuiltin::CLASS_LC,
            'RecursiveArrayIterator::key()'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $key = RecursiveArrayIteratorBuiltin::key($object);
        if (\is_int($key)) {
            $frame->returnVar->int($key);
        } else {
            $frame->returnVar->string((string) $key);
        }
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
        $object = SplIteratorSupport::receiver(
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
        $object = SplIteratorSupport::receiver(
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
