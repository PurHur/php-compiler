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
 * CachingIterator — caches current element, supports hasNext() peek (php-src ext/spl/spl_iterators.c; #13123).
 */
final class CachingIteratorBuiltin
{
    public const CLASS_LC = 'cachingiterator';

    /** php-src CIT_CALL_TOSTRING */
    public const CALL_TOSTRING = 0x00000001;

    /** php-src CIT_TOSTRING_USE_KEY */
    public const TOSTRING_USE_KEY = 0x00000002;

    /** php-src CIT_TOSTRING_USE_CURRENT */
    public const TOSTRING_USE_CURRENT = 0x00000004;

    /** php-src CIT_TOSTRING_USE_INNER */
    public const TOSTRING_USE_INNER = 0x00000008;

    /** php-src CIT_CATCH_GET_CHILD — default RecursiveTreeIterator caching flag (#6273). */
    public const CATCH_GET_CHILD = 0x00000010;

    /** php-src CIT_FULL_CACHE */
    public const FULL_CACHE = 0x00000100;

    public static function registerClass(Context $ctx): void
    {
        IteratorIteratorBuiltin::registerClass($ctx);

        if (isset($ctx->classes[self::CLASS_LC]) && self::classIsComplete($ctx->classes[self::CLASS_LC])) {
            return;
        }

        $pub = CfgFunc::FLAG_PUBLIC;
        $entry = isset($ctx->classes[self::CLASS_LC])
            ? $ctx->classes[self::CLASS_LC]
            : new ClassEntry('CachingIterator');
        $entry->parentLc = IteratorIteratorBuiltin::CLASS_LC;
        // Zend rematerialized flattened ce->interfaces (#25798).
        $entry->interfaces = [];
        foreach (['stringable', 'iterator', 'traversable', 'outeriterator', 'arrayaccess', 'countable'] as $iface) {
            if (isset($ctx->classes[$iface])) {
                $entry->interfaces[] = $iface;
            }
        }

        SplClassConstants::registerIntConstants($entry, [
            'CALL_TOSTRING' => self::CALL_TOSTRING,
            'TOSTRING_USE_KEY' => self::TOSTRING_USE_KEY,
            'TOSTRING_USE_CURRENT' => self::TOSTRING_USE_CURRENT,
            'TOSTRING_USE_INNER' => self::TOSTRING_USE_INNER,
            'CATCH_GET_CHILD' => self::CATCH_GET_CHILD,
            'FULL_CACHE' => self::FULL_CACHE,
        ]);

        $entry->constructor = new CachingIteratorConstruct();
        $entry->methods['__construct'] = $entry->constructor;
        $entry->methodVisibility['__construct'] = $pub;
        foreach ([
            'rewind' => CachingIteratorRewind::class,
            'valid' => CachingIteratorValid::class,
            'current' => CachingIteratorCurrent::class,
            'key' => CachingIteratorKey::class,
            'next' => CachingIteratorNext::class,
            'hasnext' => CachingIteratorHasNext::class,
            'getinneriterator' => CachingIteratorGetInnerIterator::class,
            'getflags' => CachingIteratorGetFlags::class,
            'setflags' => CachingIteratorSetFlags::class,
            'count' => CachingIteratorCount::class,
            'getcache' => CachingIteratorGetCache::class,
            '__tostring' => CachingIteratorToString::class,
            // ArrayAccess over FULL_CACHE (php-src spl_caching_it_offset_*; #20143).
            'offsetexists' => CachingIteratorOffsetExists::class,
            'offsetget' => CachingIteratorOffsetGet::class,
            'offsetset' => CachingIteratorOffsetSet::class,
            'offsetunset' => CachingIteratorOffsetUnset::class,
        ] as $lc => $class) {
            $entry->methods[$lc] = new $class();
            $entry->methodVisibility[$lc] = $pub;
        }
        $entry->methodNames['hasnext'] = 'hasNext';
        $entry->methodNames['getinneriterator'] = 'getInnerIterator';
        $entry->methodNames['getflags'] = 'getFlags';
        $entry->methodNames['setflags'] = 'setFlags';
        $entry->methodNames['getcache'] = 'getCache';
        $entry->methodNames['__tostring'] = '__toString';
        $entry->methodNames['offsetexists'] = 'offsetExists';
        $entry->methodNames['offsetget'] = 'offsetGet';
        $entry->methodNames['offsetset'] = 'offsetSet';
        $entry->methodNames['offsetunset'] = 'offsetUnset';
        // php-src spl_iterators.stub.php — untyped $key; @tentative-return-type (#25856).
        SplArrayStorage::attachArrayAccessArginfoNamed($entry, 'key', null, 'value', 'mixed');

        $entry->isInternal = true;
        $ctx->classes[self::CLASS_LC] = $entry;
    }

    private static function classIsComplete(ClassEntry $entry): bool
    {
        return isset(
            $entry->methods['rewind'],
            $entry->methods['valid'],
            $entry->methods['hasnext'],
            $entry->methods['getcache'],
            $entry->methods['offsetget'],
            $entry->methods['__construct']
        )
            && $entry->constructor instanceof CachingIteratorConstruct;
    }
}

/** @internal */
final class SplCachingIteratorStorage
{
    /**
     * @var array<int, array{
     *     inner: ObjectEntry,
     *     flags: int,
     *     index: int,
     *     cached: ?Variable,
     *     cachedKey: ?Variable,
     *     cachedToString: ?string,
     *     fullCache: HashTable,
     *     innerPinKey: string
     * }>
     */
    private static array $store = [];

    public static function init(ObjectEntry $object, ObjectEntry $inner, int $flags): void
    {
        $pinKey = 'caching:'.$object->id.':inner';
        if (isset(self::$store[$object->id]['innerPinKey'])) {
            SplIteratorSupport::unpinObject(
                self::$store[$object->id]['inner'],
                self::$store[$object->id]['innerPinKey']
            );
        }
        self::$store[$object->id] = [
            'inner' => SplIteratorSupport::pinObject($inner, $pinKey),
            'flags' => $flags,
            'index' => -1,
            'cached' => null,
            'cachedKey' => null,
            // php-src intern->u.caching.zstr — CALL_TOSTRING / TOSTRING_USE_INNER (#24912).
            'cachedToString' => null,
            // php-src intern->u.caching.zcache — keyed by iterator key (#19469).
            'fullCache' => new HashTable(),
            'innerPinKey' => $pinKey,
        ];
    }

    public static function inner(ObjectEntry $object): ObjectEntry
    {
        return self::state($object)['inner'];
    }

    public static function flags(ObjectEntry $object): int
    {
        return self::state($object)['flags'];
    }

    /**
     * php-src CachingIterator::setFlags — once CIT_CALL_TOSTRING is set it cannot
     * be cleared ("Unsetting flag CALL_TO_STRING is not possible"; #24252).
     */
    public static function setFlags(ObjectEntry $object, int $flags): void
    {
        $current = self::$store[$object->id]['flags'];
        if (0 !== ($current & CachingIteratorBuiltin::CALL_TOSTRING)
            && 0 === ($flags & CachingIteratorBuiltin::CALL_TOSTRING)) {
            throw new \InvalidArgumentException('Unsetting flag CALL_TO_STRING is not possible');
        }
        self::$store[$object->id]['flags'] = $flags;
    }

    public static function rewind(Frame $frame, ObjectEntry $object): void
    {
        $state = &self::$store[$object->id];
        SplDualIteratorStorage::callInner($frame, $state['inner'], 'rewind');
        $state['index'] = -1;
        $state['cached'] = null;
        $state['cachedKey'] = null;
        $state['cachedToString'] = null;
        $state['fullCache'] = new HashTable();
        self::next($frame, $object);
    }

    public static function valid(Frame $frame, ObjectEntry $object): bool
    {
        $state = self::state($object);
        if ($state['index'] < 0) {
            return false;
        }
        // Do not rewind/resync the inner here — Generators are not rewound (#22876).
        // Sequential next()/rewind() already keep the inner at the wrapper index.
        $valid = SplDualIteratorStorage::callInner($frame, $state['inner'], 'valid')->resolveIndirect();

        return Variable::TYPE_BOOLEAN === $valid->type && $valid->toBool();
    }

    public static function current(ObjectEntry $object): Variable
    {
        $state = self::state($object);
        if ($state['index'] < 0 || null === $state['cached']) {
            $null = new Variable();
            $null->null();

            return $null;
        }

        return $state['cached'];
    }

    public static function key(ObjectEntry $object): Variable
    {
        $state = self::state($object);
        if ($state['index'] < 0 || null === $state['cachedKey']) {
            $null = new Variable();
            $null->null();

            return $null;
        }

        return $state['cachedKey'];
    }

    public static function next(Frame $frame, ObjectEntry $object): void
    {
        $state = &self::$store[$object->id];
        // php-src spl_dual_it_free clears caching.zstr before fetch (#24912).
        $state['cachedToString'] = null;
        if ($state['index'] < 0) {
            $valid = SplDualIteratorStorage::callInner($frame, $state['inner'], 'valid')->resolveIndirect();
            if (Variable::TYPE_BOOLEAN !== $valid->type || !$valid->toBool()) {
                return;
            }
            self::updateCache($frame, $object);
            $state['index'] = 0;

            return;
        }

        SplDualIteratorStorage::callInner($frame, $state['inner'], 'next');
        ++$state['index'];
        $valid = SplDualIteratorStorage::callInner($frame, $state['inner'], 'valid')->resolveIndirect();
        if (Variable::TYPE_BOOLEAN === $valid->type && $valid->toBool()) {
            self::updateCache($frame, $object);
        } else {
            $state['cached'] = null;
            $state['cachedKey'] = null;
        }
    }

    public static function hasNext(Frame $frame, ObjectEntry $object): bool
    {
        $state = self::state($object);
        if ($state['index'] < 0) {
            $valid = SplDualIteratorStorage::callInner($frame, $state['inner'], 'valid')->resolveIndirect();

            return Variable::TYPE_BOOLEAN === $valid->type && $valid->toBool();
        }

        self::syncInnerPosition($frame, $state['inner'], $state['index']);
        SplDualIteratorStorage::callInner($frame, $state['inner'], 'next');
        $valid = SplDualIteratorStorage::callInner($frame, $state['inner'], 'valid')->resolveIndirect();
        $hasNext = Variable::TYPE_BOOLEAN === $valid->type && $valid->toBool();
        self::syncInnerPosition($frame, $state['inner'], $state['index']);

        return $hasNext;
    }

    public static function count(ObjectEntry $object): int
    {
        return self::requireFullCache($object)->getNumElements();
    }

    /**
     * php-src CachingIterator::getCache — return intern->u.caching.zcache (#19469).
     *
     * @throws \BadMethodCallException when FULL_CACHE is not set
     */
    public static function getCache(ObjectEntry $object): HashTable
    {
        return self::requireFullCache($object);
    }

    /**
     * php-src spl_caching_it_offset_exists — FULL_CACHE ArrayAccess (#20143).
     *
     * @throws \BadMethodCallException when FULL_CACHE is not set
     */
    public static function offsetExists(ObjectEntry $object, Variable $offset): bool
    {
        $cache = self::requireFullCache($object);

        return null !== self::findCacheOffset($cache, $offset);
    }

    /**
     * php-src spl_caching_it_offset_get — FULL_CACHE ArrayAccess (#20143).
     *
     * @throws \BadMethodCallException when FULL_CACHE is not set
     */
    public static function offsetGet(ObjectEntry $object, Variable $offset): Variable
    {
        $found = self::findCacheOffset(self::requireFullCache($object), $offset);
        if (null === $found) {
            $null = new Variable();
            $null->null();

            return $null;
        }
        $resolved = $found->resolveIndirect();
        $out = new Variable($resolved->type);
        $out->copyFrom($resolved);

        return $out;
    }

    /**
     * php-src spl_caching_it_offset_set — mutate FULL_CACHE (#20143).
     *
     * @throws \BadMethodCallException when FULL_CACHE is not set
     */
    public static function offsetSet(ObjectEntry $object, Variable $offset, Variable $value): void
    {
        $cache = self::requireFullCache($object);
        self::storeFullCacheEntry($cache, $offset, $value);
    }

    /**
     * php-src spl_caching_it_offset_unset — mutate FULL_CACHE (#20143).
     *
     * @throws \BadMethodCallException when FULL_CACHE is not set
     */
    public static function offsetUnset(ObjectEntry $object, Variable $offset): void
    {
        $cache = self::requireFullCache($object);
        [$keyVar] = self::cacheOffsetKeyVar($offset);
        $cache->offsetUnset($keyVar);
    }

    /**
     * php-src CachingIterator::__toString (spl_iterators.c) — flag gate + TOSTRING_USE_* / CALL_TOSTRING (#24907, #24256).
     *
     * Without CIT_CALL_TOSTRING|CIT_TOSTRING_USE_KEY|CURRENT|INNER → BadMethodCallException
     * (FULL_CACHE alone must not stringify).
     */
    public static function toString(Frame $frame, ObjectEntry $object): string
    {
        $state = self::state($object);
        $flags = $state['flags'];
        $fetchMask = CachingIteratorBuiltin::CALL_TOSTRING
            | CachingIteratorBuiltin::TOSTRING_USE_KEY
            | CachingIteratorBuiltin::TOSTRING_USE_CURRENT
            | CachingIteratorBuiltin::TOSTRING_USE_INNER;
        if (0 === ($flags & $fetchMask)) {
            // php-src: "%s does not fetch string value (see CachingIterator::__construct)"
            throw new \BadMethodCallException(
                $object->class->name.' does not fetch string value (see CachingIterator::__construct)'
            );
        }

        // php-src: CIT_TOSTRING_USE_KEY takes precedence over USE_CURRENT / CALL_TOSTRING zstr.
        if (0 !== ($flags & CachingIteratorBuiltin::TOSTRING_USE_KEY)) {
            if ($state['index'] < 0 || null === $state['cachedKey']) {
                return '';
            }

            return self::stringifyVariable($frame, $state['cachedKey']->resolveIndirect());
        }
        if (0 !== ($flags & CachingIteratorBuiltin::TOSTRING_USE_CURRENT)) {
            if ($state['index'] < 0 || null === $state['cached']) {
                return '';
            }

            return self::stringifyVariable($frame, $state['cached']->resolveIndirect());
        }
        // CIT_TOSTRING_USE_INNER / CIT_CALL_TOSTRING — php-src returns cached zstr from next (#24912).
        if (
            0 !== ($flags & CachingIteratorBuiltin::TOSTRING_USE_INNER)
            || 0 !== ($flags & CachingIteratorBuiltin::CALL_TOSTRING)
        ) {
            return $state['cachedToString'] ?? '';
        }

        return '';
    }

    /** convert_to_string / zval_get_string for cached current or key (#24256 / #24907 / #25358). */
    private static function stringifyVariable(Frame $frame, Variable $resolved): string
    {
        return match ($resolved->type) {
            Variable::TYPE_STRING => $resolved->toString(),
            Variable::TYPE_INTEGER => (string) $resolved->toInt(),
            Variable::TYPE_FLOAT => (string) $resolved->toFloat(),
            Variable::TYPE_BOOLEAN => $resolved->toBool() ? '1' : '',
            Variable::TYPE_NULL => '',
            Variable::TYPE_ARRAY => self::stringifyArray($frame),
            Variable::TYPE_OBJECT => self::stringifyObjectCurrent($frame, $resolved->toObject()),
            default => 'Object',
        };
    }

    /**
     * Zend _convert_to_string() array branch (zend_operators.c) — E_WARNING then "Array" (#25358).
     */
    private static function stringifyArray(Frame $frame): string
    {
        if (null !== $frame->vmContext) {
            $frame->vmContext->errors->languageWarning(
                'Array to string conversion',
                null,
                0,
                $frame->vmContext,
                $frame
            );
        }

        return 'Array';
    }

    private static function stringifyObjectCurrent(Frame $frame, ObjectEntry $current): string
    {
        if (null === $frame->vmContext || null === $frame->vmContext->runtime) {
            throw new \LogicException('CachingIterator::__toString() requires VM runtime');
        }

        return $frame->vmContext->runtime->vm->castObjectToString($current);
    }

    private static function updateCache(Frame $frame, ObjectEntry $object): void
    {
        $state = &self::$store[$object->id];
        $current = SplDualIteratorStorage::callInner($frame, $state['inner'], 'current');
        $key = SplDualIteratorStorage::callInner($frame, $state['inner'], 'key');
        $state['cached'] = $current->resolveIndirect();
        $state['cachedKey'] = $key->resolveIndirect();
        if (0 !== ($state['flags'] & CachingIteratorBuiltin::FULL_CACHE)) {
            // php-src spl_caching_it_next: array_set_zval_key(zcache, key, data)
            self::storeFullCacheEntry($state['fullCache'], $state['cachedKey'], $state['cached']);
        }
        // php-src: CIT_TOSTRING_USE_INNER → zval_get_string(inner); else CALL_TOSTRING → current (#24912).
        if (0 !== ($state['flags'] & CachingIteratorBuiltin::TOSTRING_USE_INNER)) {
            $state['cachedToString'] = self::stringifyObjectCurrent($frame, $state['inner']);
        } elseif (0 !== ($state['flags'] & CachingIteratorBuiltin::CALL_TOSTRING)) {
            $state['cachedToString'] = self::stringifyVariable($frame, $state['cached']);
        }
    }

    /** Mirror php-src array_set_zval_key for FULL_CACHE accumulation. */
    private static function storeFullCacheEntry(HashTable $cache, Variable $key, Variable $value): void
    {
        $resolvedKey = $key->resolveIndirect();
        $resolvedValue = $value->resolveIndirect();
        $stored = new Variable($resolvedValue->type);
        $stored->copyFrom($resolvedValue);
        if (Variable::TYPE_INTEGER === $resolvedKey->type) {
            $idx = $resolvedKey->toInt();
            if (null !== $cache->findIndex($idx)) {
                $cache->updateIndex($idx, $stored);
            } else {
                $cache->addIndex($idx, $stored);
            }

            return;
        }
        if (Variable::TYPE_NULL === $resolvedKey->type) {
            $strKey = '';
        } elseif (Variable::TYPE_FLOAT === $resolvedKey->type) {
            $idx = (int) $resolvedKey->toFloat();
            if (null !== $cache->findIndex($idx)) {
                $cache->updateIndex($idx, $stored);
            } else {
                $cache->addIndex($idx, $stored);
            }

            return;
        } elseif (Variable::TYPE_STRING === $resolvedKey->type) {
            $strKey = $resolvedKey->toString();
        } elseif (Variable::TYPE_BOOLEAN === $resolvedKey->type) {
            $strKey = $resolvedKey->toBool() ? '1' : '';
        } else {
            // Objects/arrays as keys: Zend array_set_zval_key converts / warns;
            // numeric list append keeps iteration progressing for scalar workloads.
            $cache->append($stored);

            return;
        }
        if (null !== $cache->find($strKey)) {
            $cache->update($strKey, $stored);
        } else {
            $cache->add($strKey, $stored);
        }
    }

    private static function findCacheOffset(HashTable $cache, Variable $offset): ?Variable
    {
        [$keyVar, $isInt] = self::cacheOffsetKeyVar($offset);

        return $isInt
            ? $cache->findIndex($keyVar->toInt())
            : $cache->find($keyVar->toString());
    }

    /** @return array{0: Variable, 1: bool} */
    private static function cacheOffsetKeyVar(Variable $offset): array
    {
        $resolved = $offset->resolveIndirect();
        if (Variable::TYPE_INTEGER === $resolved->type) {
            $key = new Variable(Variable::TYPE_INTEGER);
            $key->int($resolved->toInt());

            return [$key, true];
        }
        if (Variable::TYPE_STRING === $resolved->type) {
            $key = new Variable(Variable::TYPE_STRING);
            $key->string($resolved->toString());

            return [$key, false];
        }
        if (Variable::TYPE_NULL === $resolved->type) {
            $key = new Variable(Variable::TYPE_STRING);
            $key->string('');

            return [$key, false];
        }
        if (Variable::TYPE_FLOAT === $resolved->type) {
            $key = new Variable(Variable::TYPE_INTEGER);
            $key->int((int) $resolved->toFloat());

            return [$key, true];
        }
        if (Variable::TYPE_BOOLEAN === $resolved->type) {
            $key = new Variable(Variable::TYPE_STRING);
            $key->string($resolved->toBool() ? '1' : '');

            return [$key, false];
        }

        throw new \TypeError('Illegal offset type');
    }

    private static function requireFullCache(ObjectEntry $object): HashTable
    {
        $state = self::state($object);
        if (0 === ($state['flags'] & CachingIteratorBuiltin::FULL_CACHE)) {
            throw new \BadMethodCallException(
                'CachingIterator does not use a full cache (see CachingIterator::__construct)'
            );
        }

        return $state['fullCache'];
    }

    private static function syncInnerPosition(Frame $frame, ObjectEntry $inner, int $wrapperIndex): void
    {
        SplDualIteratorStorage::callInner($frame, $inner, 'rewind');
        for ($i = 0; $i < $wrapperIndex; ++$i) {
            SplDualIteratorStorage::callInner($frame, $inner, 'next');
        }
    }

    public static function hasState(ObjectEntry $object): bool
    {
        return isset(self::$store[$object->id]);
    }

    /** @return array{inner: ObjectEntry, flags: int, index: int, cached: ?Variable, cachedKey: ?Variable, fullCache: HashTable, innerPinKey: string} */
    private static function state(ObjectEntry $object): array
    {
        if (!isset(self::$store[$object->id])) {
            throw new \LogicException('Iterator wrapper state missing');
        }

        return self::$store[$object->id];
    }
}

final class CachingIteratorConstruct extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            CachingIteratorBuiltin::CLASS_LC,
            'CachingIterator::__construct()'
        );
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError(
                'CachingIterator::__construct() expects at least 1 argument, '
                .(\count($frame->calledArgs) - 1).' given'
            );
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('CachingIterator::__construct() requires VM context');
        }
        [$iteratorArg, $flagsArg] = self::resolveConstructArgs($frame);
        $inner = SplDualIteratorStorage::resolveIterator(
            $frame->vmContext,
            $frame,
            $iteratorArg
        );
        // php-src: int $flags = self::CALL_TOSTRING; explicit null → 0 (Z_PARAM_LONG_OR_NULL).
        $flags = self::resolveConstructFlags($flagsArg, 'CachingIterator::__construct');
        // php-src zim_cachingiterator_construct — store iterator only; first rewind is on
        // iteration (foreach / explicit rewind). Construct-time rewind breaks Generators (#22876).
        SplCachingIteratorStorage::init($object, $inner, $flags);
    }

    /**
     * php-cfg hoists ClassConstFetch before inline Expr_New call args — compiler may
     * ARG_SEND flags before the nested iterator (#17400).
     *
     * @return array{0: Variable, 1: ?Variable}
     */
    private static function resolveConstructArgs(Frame $frame): array
    {
        if (isset($frame->calledArgs[2])) {
            $first = $frame->calledArgs[1]->resolveIndirect();
            $second = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_INTEGER === $first->type && Variable::TYPE_OBJECT === $second->type) {
                return [$frame->calledArgs[2], $frame->calledArgs[1]];
            }
        }

        return [$frame->calledArgs[1], $frame->calledArgs[2] ?? null];
    }

    /**
     * Resolve construct $flags (php-src spl_iterators.c / Z_PARAM_LONG_OR_NULL; #22336).
     *
     * Omitted arg → CALL_TOSTRING; explicit null → 0; int → value.
     */
    public static function resolveConstructFlags(?Variable $flagsArg, string $function = 'CachingIterator::__construct'): int
    {
        if (null === $flagsArg) {
            return CachingIteratorBuiltin::CALL_TOSTRING;
        }
        $flagsArg = $flagsArg->resolveIndirect();
        if (Variable::TYPE_NULL === $flagsArg->type) {
            return 0;
        }
        if (Variable::TYPE_INTEGER !== $flagsArg->type) {
            throw new \TypeError(
                $function.'(): Argument #2 ($flags) must be of type int, '
                .self::typeLabel($flagsArg).' given'
            );
        }

        return $flagsArg->toInt();
    }

    private static function typeLabel(Variable $var): string
    {
        return match ($var->type) {
            Variable::TYPE_NULL => 'null',
            Variable::TYPE_BOOLEAN => 'bool',
            Variable::TYPE_INTEGER => 'int',
            Variable::TYPE_FLOAT => 'float',
            Variable::TYPE_STRING => 'string',
            Variable::TYPE_ARRAY => 'array',
            Variable::TYPE_OBJECT => 'object',
            default => 'mixed',
        };
    }
}

final class CachingIteratorRewind extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('rewind');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            CachingIteratorBuiltin::CLASS_LC,
            'CachingIterator::rewind()'
        );
        SplCachingIteratorStorage::rewind($frame, $object);
    }
}

final class CachingIteratorValid extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('valid');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            CachingIteratorBuiltin::CLASS_LC,
            'CachingIterator::valid()'
        );
        SplIteratorSupport::setReturnBool($frame, SplCachingIteratorStorage::valid($frame, $object));
    }
}

final class CachingIteratorCurrent extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('current');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            CachingIteratorBuiltin::CLASS_LC,
            'CachingIterator::current()'
        );
        SplIteratorSupport::copyReturnFrom($frame, SplCachingIteratorStorage::current($object));
    }
}

final class CachingIteratorKey extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('key');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            CachingIteratorBuiltin::CLASS_LC,
            'CachingIterator::key()'
        );
        SplIteratorSupport::copyReturnFrom($frame, SplCachingIteratorStorage::key($object));
    }
}

final class CachingIteratorNext extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('next');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            CachingIteratorBuiltin::CLASS_LC,
            'CachingIterator::next()'
        );
        SplCachingIteratorStorage::next($frame, $object);
    }
}

final class CachingIteratorHasNext extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('hasNext');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            CachingIteratorBuiltin::CLASS_LC,
            'CachingIterator::hasNext()'
        );
        // php-src zim_CachingIterator_hasNext — ZEND_PARSE_PARAMETERS_NONE (#30948)
        $this->requireExactUserArgCount($frame, 'CachingIterator::hasNext', 0);
        SplIteratorSupport::setReturnBool($frame, SplCachingIteratorStorage::hasNext($frame, $object));
    }
}

final class CachingIteratorGetInnerIterator extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getInnerIterator');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            CachingIteratorBuiltin::CLASS_LC,
            'CachingIterator::getInnerIterator()'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->object(SplCachingIteratorStorage::inner($object));
    }
}

final class CachingIteratorGetFlags extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getFlags');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            CachingIteratorBuiltin::CLASS_LC,
            'CachingIterator::getFlags()'
        );
        // php-src zim_CachingIterator_getFlags — ZEND_PARSE_PARAMETERS_NONE (#30948)
        $this->requireExactUserArgCount($frame, 'CachingIterator::getFlags', 0);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int(SplCachingIteratorStorage::flags($object));
    }
}

final class CachingIteratorSetFlags extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('setFlags');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            CachingIteratorBuiltin::CLASS_LC,
            'CachingIterator::setFlags()'
        );
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError(
                'CachingIterator::setFlags() expects exactly 1 argument, '
                .(\count($frame->calledArgs) - 1).' given'
            );
        }
        $flagsArg = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_INTEGER !== $flagsArg->type) {
            throw new \TypeError(
                'CachingIterator::setFlags(): Argument #1 ($flags) must be of type int, '
                .self::typeLabel($flagsArg).' given'
            );
        }
        SplCachingIteratorStorage::setFlags($object, $flagsArg->toInt());
    }

    /** @param Variable $var */
    private static function typeLabel(Variable $var): string
    {
        return match ($var->type) {
            Variable::TYPE_NULL => 'null',
            Variable::TYPE_BOOLEAN => 'bool',
            Variable::TYPE_INTEGER => 'int',
            Variable::TYPE_FLOAT => 'float',
            Variable::TYPE_STRING => 'string',
            Variable::TYPE_ARRAY => 'array',
            Variable::TYPE_OBJECT => 'object',
            default => 'mixed',
        };
    }
}

final class CachingIteratorCount extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('count');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            CachingIteratorBuiltin::CLASS_LC,
            'CachingIterator::count()'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int(SplCachingIteratorStorage::count($object));
    }
}

final class CachingIteratorGetCache extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getCache');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            CachingIteratorBuiltin::CLASS_LC,
            'CachingIterator::getCache()'
        );
        // php-src zim_CachingIterator_getCache — ZEND_PARSE_PARAMETERS_NONE (#30948)
        $this->requireExactUserArgCount($frame, 'CachingIterator::getCache', 0);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->array(SplCachingIteratorStorage::getCache($object));
    }
}

final class CachingIteratorToString extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__toString');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            CachingIteratorBuiltin::CLASS_LC,
            'CachingIterator::__toString()'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->string(SplCachingIteratorStorage::toString($frame, $object));
    }
}

/** php-src CachingIterator::offsetExists — FULL_CACHE ArrayAccess (#20143). */
final class CachingIteratorOffsetExists extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('offsetExists');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            CachingIteratorBuiltin::CLASS_LC,
            'CachingIterator::offsetExists()'
        );
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError(
                'CachingIterator::offsetExists() expects exactly 1 argument, '
                .(\count($frame->calledArgs) - 1).' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool(
            SplCachingIteratorStorage::offsetExists($object, $frame->calledArgs[1])
        );
    }
}

/** php-src CachingIterator::offsetGet — FULL_CACHE ArrayAccess (#20143). */
final class CachingIteratorOffsetGet extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('offsetGet');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            CachingIteratorBuiltin::CLASS_LC,
            'CachingIterator::offsetGet()'
        );
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError(
                'CachingIterator::offsetGet() expects exactly 1 argument, '
                .(\count($frame->calledArgs) - 1).' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        SplIteratorSupport::copyReturnFrom(
            $frame,
            SplCachingIteratorStorage::offsetGet($object, $frame->calledArgs[1])
        );
    }
}

/** php-src CachingIterator::offsetSet — FULL_CACHE ArrayAccess (#20143). */
final class CachingIteratorOffsetSet extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('offsetSet');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            CachingIteratorBuiltin::CLASS_LC,
            'CachingIterator::offsetSet()'
        );
        if (\count($frame->calledArgs) < 3) {
            throw new \ArgumentCountError(
                'CachingIterator::offsetSet() expects exactly 2 arguments, '
                .(\count($frame->calledArgs) - 1).' given'
            );
        }
        SplCachingIteratorStorage::offsetSet(
            $object,
            $frame->calledArgs[1],
            $frame->calledArgs[2]
        );
    }
}

/** php-src CachingIterator::offsetUnset — FULL_CACHE ArrayAccess (#20143). */
final class CachingIteratorOffsetUnset extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('offsetUnset');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            CachingIteratorBuiltin::CLASS_LC,
            'CachingIterator::offsetUnset()'
        );
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError(
                'CachingIterator::offsetUnset() expects exactly 1 argument, '
                .(\count($frame->calledArgs) - 1).' given'
            );
        }
        SplCachingIteratorStorage::offsetUnset($object, $frame->calledArgs[1]);
    }
}
