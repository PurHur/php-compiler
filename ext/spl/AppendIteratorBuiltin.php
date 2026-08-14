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
 * AppendIterator — chain multiple iterators (php-src ext/spl/spl_iterators.c; #13211).
 */
final class AppendIteratorBuiltin
{
    public const CLASS_LC = 'appenditerator';

    /** @var array<int, array{iterators: list<ObjectEntry>, index: int}> */
    private static array $store = [];

    public static function registerClass(Context $ctx): void
    {
        IteratorIteratorBuiltin::registerClass($ctx);

        if (isset($ctx->classes[self::CLASS_LC]) && self::classIsComplete($ctx->classes[self::CLASS_LC])) {
            return;
        }

        $pub = CfgFunc::FLAG_PUBLIC;
        $entry = isset($ctx->classes[self::CLASS_LC])
            ? $ctx->classes[self::CLASS_LC]
            : new ClassEntry('AppendIterator');
        $entry->parentLc = IteratorIteratorBuiltin::CLASS_LC;
        // Zend rematerialized flattened ce->interfaces (#25798).
        $entry->interfaces = [];
        foreach (['iterator', 'traversable', 'outeriterator'] as $iface) {
            if (isset($ctx->classes[$iface])) {
                $entry->interfaces[] = $iface;
            }
        }

        $entry->constructor = new AppendIteratorConstruct();
        $entry->methods['__construct'] = $entry->constructor;
        $entry->methodVisibility['__construct'] = $pub;
        foreach ([
            'append' => AppendIteratorAppend::class,
            'rewind' => AppendIteratorRewind::class,
            'valid' => AppendIteratorValid::class,
            'current' => AppendIteratorCurrent::class,
            'key' => AppendIteratorKey::class,
            'next' => AppendIteratorNext::class,
            'getinneriterator' => AppendIteratorGetInnerIterator::class,
            'getiteratorindex' => AppendIteratorGetIteratorIndex::class,
            'getarrayiterator' => AppendIteratorGetArrayIterator::class,
        ] as $lc => $class) {
            $entry->methods[$lc] = new $class();
            $entry->methodVisibility[$lc] = $pub;
        }
        $entry->methodNames['getinneriterator'] = 'getInnerIterator';
        $entry->methodNames['getiteratorindex'] = 'getIteratorIndex';
        $entry->methodNames['getarrayiterator'] = 'getArrayIterator';

        $entry->isInternal = true;
        $ctx->classes[self::CLASS_LC] = $entry;
    }

    private static function classIsComplete(ClassEntry $entry): bool
    {
        return isset($entry->methods['rewind'], $entry->methods['append'], $entry->methods['__construct']);
    }

    public static function init(ObjectEntry $object): void
    {
        self::$store[$object->id] = [
            'iterators' => [],
            'index' => 0,
        ];
    }

    public static function appendIterator(ObjectEntry $object, ObjectEntry $inner): void
    {
        self::ensureState($object);
        $index = \count(self::$store[$object->id]['iterators']);
        $pinKey = 'append:'.$object->id.':'.$index.':'.$inner->id;
        SplIteratorSupport::pinObject($inner, $pinKey);
        self::$store[$object->id]['iterators'][] = $inner;
        self::$store[$object->id]['pinKeys'][$index] = $pinKey;
    }

    public static function currentIterator(ObjectEntry $object): ?ObjectEntry
    {
        self::ensureState($object);
        $state = self::$store[$object->id];
        if ([] === $state['iterators']) {
            return null;
        }
        $index = $state['index'];
        if ($index < 0 || $index >= \count($state['iterators'])) {
            return null;
        }

        return $state['iterators'][$index];
    }

    /**
     * php-src spl_append_it_get_iterator_index: null when no current inner
     * (empty / exhausted past last iterator), else the active inner index.
     */
    public static function iteratorIndex(ObjectEntry $object): ?int
    {
        self::ensureState($object);
        // currentIterator() is null when index is OOB — same condition as !valid().
        if (null === self::currentIterator($object)) {
            return null;
        }

        return self::$store[$object->id]['index'];
    }

    /** @return list<ObjectEntry> */
    public static function appendedIterators(ObjectEntry $object): array
    {
        self::ensureState($object);

        return self::$store[$object->id]['iterators'];
    }

    public static function createArrayIterator(Frame $frame, ObjectEntry $object): Variable
    {
        if (null === $frame->vmContext) {
            throw new \LogicException('AppendIterator::getArrayIterator() requires VM context');
        }
        $class = $frame->vmContext->classes[ArrayIteratorBuiltin::CLASS_LC] ?? null;
        if (null === $class) {
            throw new \LogicException('ArrayIterator is not registered in this compiler build');
        }
        $table = new HashTable();
        foreach (self::appendedIterators($object) as $index => $inner) {
            $var = new Variable(Variable::TYPE_OBJECT);
            $var->object($inner);
            $table->addIndex($index, $var);
        }
        $entry = new ObjectEntry($class);
        $entry->constructed = true;
        SplArrayStorage::init($entry, $table, 0, null, []);
        $result = new Variable(Variable::TYPE_OBJECT);
        $result->object($entry);

        return $result;
    }

    public static function rewind(Frame $frame, ObjectEntry $object): void
    {
        self::ensureState($object);
        self::$store[$object->id]['index'] = 0;
        if ([] === self::$store[$object->id]['iterators']) {
            return;
        }
        SplDualIteratorStorage::callInner($frame, self::$store[$object->id]['iterators'][0], 'rewind');
        self::syncToValid($frame, $object);
    }

    public static function next(Frame $frame, ObjectEntry $object): void
    {
        self::ensureState($object);
        $inner = self::currentIterator($object);
        if (null === $inner) {
            return;
        }
        SplDualIteratorStorage::callInner($frame, $inner, 'next');
        if (!SplDualIteratorStorage::callInner($frame, $inner, 'valid')->resolveIndirect()->toBool()) {
            ++self::$store[$object->id]['index'];
            if (self::$store[$object->id]['index'] < \count(self::$store[$object->id]['iterators'])) {
                $next = self::$store[$object->id]['iterators'][self::$store[$object->id]['index']];
                SplDualIteratorStorage::callInner($frame, $next, 'rewind');
            }
        }
        self::syncToValid($frame, $object);
    }

    private static function syncToValid(Frame $frame, ObjectEntry $object): void
    {
        while (true) {
            $inner = self::currentIterator($object);
            if (null === $inner) {
                return;
            }
            if (SplDualIteratorStorage::callInner($frame, $inner, 'valid')->resolveIndirect()->toBool()) {
                return;
            }
            SplDualIteratorStorage::callInner($frame, $inner, 'next');
            if (!SplDualIteratorStorage::callInner($frame, $inner, 'valid')->resolveIndirect()->toBool()) {
                ++self::$store[$object->id]['index'];
                if (self::$store[$object->id]['index'] < \count(self::$store[$object->id]['iterators'])) {
                    $next = self::$store[$object->id]['iterators'][self::$store[$object->id]['index']];
                    SplDualIteratorStorage::callInner($frame, $next, 'rewind');
                } else {
                    return;
                }
            }
        }
    }

    private static function ensureState(ObjectEntry $object): void
    {
        if (!isset(self::$store[$object->id])) {
            self::init($object);
        }
    }
}

final class AppendIteratorConstruct extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        // php-src spl_append_it_construct — ZEND_PARSE_PARAMETERS_NONE (#31071).
        $this->requireExactUserArgCount($frame, 'AppendIterator::__construct', 0);
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            AppendIteratorBuiltin::CLASS_LC,
            'AppendIterator::__construct()'
        );
        AppendIteratorBuiltin::init($object);
    }
}

final class AppendIteratorAppend extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('append');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            AppendIteratorBuiltin::CLASS_LC,
            'AppendIterator::append()'
        );
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError(
                'AppendIterator::append() expects exactly 1 argument, '
                .(\count($frame->calledArgs) - 1).' given'
            );
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('AppendIterator::append() requires VM context');
        }
        $inner = SplDualIteratorStorage::resolveIterator(
            $frame->vmContext,
            $frame,
            $frame->calledArgs[1]
        );
        AppendIteratorBuiltin::appendIterator($object, $inner);
    }
}

final class AppendIteratorRewind extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('rewind');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            AppendIteratorBuiltin::CLASS_LC,
            'AppendIterator::rewind()'
        );
        AppendIteratorBuiltin::rewind($frame, $object);
    }
}

final class AppendIteratorNext extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('next');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            AppendIteratorBuiltin::CLASS_LC,
            'AppendIterator::next()'
        );
        AppendIteratorBuiltin::next($frame, $object);
    }
}

final class AppendIteratorValid extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('valid');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            AppendIteratorBuiltin::CLASS_LC,
            'AppendIterator::valid()'
        );
        $inner = AppendIteratorBuiltin::currentIterator($object);
        $valid = null !== $inner
            && SplDualIteratorStorage::callInner($frame, $inner, 'valid')->resolveIndirect()->toBool();
        SplIteratorSupport::setReturnBool($frame, $valid);
    }
}

final class AppendIteratorCurrent extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('current');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            AppendIteratorBuiltin::CLASS_LC,
            'AppendIterator::current()'
        );
        $inner = AppendIteratorBuiltin::currentIterator($object);
        if (null === $inner) {
            throw new \RuntimeException('Cannot fetch current() on invalid AppendIterator position');
        }
        SplIteratorSupport::copyReturnFrom(
            $frame,
            SplDualIteratorStorage::callInner($frame, $inner, 'current')
        );
    }
}

final class AppendIteratorKey extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('key');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            AppendIteratorBuiltin::CLASS_LC,
            'AppendIterator::key()'
        );
        $inner = AppendIteratorBuiltin::currentIterator($object);
        if (null === $inner) {
            throw new \RuntimeException('Cannot fetch key() on invalid AppendIterator position');
        }
        SplIteratorSupport::copyReturnFrom(
            $frame,
            SplDualIteratorStorage::callInner($frame, $inner, 'key')
        );
    }
}

final class AppendIteratorGetInnerIterator extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getInnerIterator');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            AppendIteratorBuiltin::CLASS_LC,
            'AppendIterator::getInnerIterator()'
        );
        // Inherited zim_IteratorIterator_getInnerIterator — ACE cites IteratorIterator (#30949).
        $this->requireExactUserArgCount($frame, 'IteratorIterator::getInnerIterator', 0);
        if (null === $frame->returnVar) {
            return;
        }
        $inner = AppendIteratorBuiltin::currentIterator($object);
        if (null === $inner) {
            $frame->returnVar->null();
        } else {
            $frame->returnVar->object($inner);
        }
    }
}

final class AppendIteratorGetIteratorIndex extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getIteratorIndex');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            AppendIteratorBuiltin::CLASS_LC,
            'AppendIterator::getIteratorIndex()'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $index = AppendIteratorBuiltin::iteratorIndex($object);
        if (null === $index) {
            $frame->returnVar->null();
        } else {
            $frame->returnVar->int($index);
        }
    }
}

final class AppendIteratorGetArrayIterator extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getArrayIterator');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            AppendIteratorBuiltin::CLASS_LC,
            'AppendIterator::getArrayIterator()'
        );
        // php-src zim_AppendIterator_getArrayIterator — ZEND_PARSE_PARAMETERS_NONE (#30949).
        $this->requireExactUserArgCount($frame, 'AppendIterator::getArrayIterator', 0);
        SplIteratorSupport::copyReturnFrom(
            $frame,
            AppendIteratorBuiltin::createArrayIterator($frame, $object)
        );
    }
}
