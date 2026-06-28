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
        foreach (['OuterIterator', 'Traversable', 'Iterator', 'ArrayAccess', 'Countable', 'Stringable'] as $iface) {
            if (isset($ctx->classes[strtolower($iface)])
                && !\in_array($iface, $entry->interfaces, true)) {
                $entry->interfaces[] = $iface;
            }
        }

        SplClassConstants::registerIntConstants($entry, [
            'CALL_TOSTRING' => self::CALL_TOSTRING,
            'TOSTRING_USE_KEY' => self::TOSTRING_USE_KEY,
            'TOSTRING_USE_CURRENT' => self::TOSTRING_USE_CURRENT,
            'TOSTRING_USE_INNER' => self::TOSTRING_USE_INNER,
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
            '__tostring' => CachingIteratorToString::class,
        ] as $lc => $class) {
            $entry->methods[$lc] = new $class();
            $entry->methodVisibility[$lc] = $pub;
        }
        $entry->methodNames['hasnext'] = 'hasNext';
        $entry->methodNames['getinneriterator'] = 'getInnerIterator';
        $entry->methodNames['getflags'] = 'getFlags';
        $entry->methodNames['setflags'] = 'setFlags';
        $entry->methodNames['__tostring'] = '__toString';

        $entry->isInternal = true;
        $ctx->classes[self::CLASS_LC] = $entry;
    }

    private static function classIsComplete(ClassEntry $entry): bool
    {
        return isset($entry->methods['rewind'], $entry->methods['valid'], $entry->methods['hasnext']);
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
     *     fullCache: list<true>
     * }>
     */
    private static array $store = [];

    public static function init(ObjectEntry $object, ObjectEntry $inner, int $flags): void
    {
        self::$store[$object->id] = [
            'inner' => $inner,
            'flags' => $flags,
            'index' => -1,
            'cached' => null,
            'cachedKey' => null,
            'fullCache' => [],
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

    public static function setFlags(ObjectEntry $object, int $flags): void
    {
        self::$store[$object->id]['flags'] = $flags;
    }

    public static function rewind(Frame $frame, ObjectEntry $object): void
    {
        $state = &self::$store[$object->id];
        SplDualIteratorStorage::callInner($frame, $state['inner'], 'rewind');
        $state['index'] = -1;
        $state['cached'] = null;
        $state['cachedKey'] = null;
        $state['fullCache'] = [];
        self::next($frame, $object);
    }

    public static function valid(Frame $frame, ObjectEntry $object): bool
    {
        $state = self::state($object);
        if ($state['index'] < 0) {
            return false;
        }
        self::syncInnerPosition($frame, $state['inner'], $state['index']);
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
        $state = self::state($object);
        if (0 === ($state['flags'] & CachingIteratorBuiltin::FULL_CACHE)) {
            throw new \BadMethodCallException(
                'CachingIterator does not use a full cache (see CachingIterator::__construct)'
            );
        }

        return \count($state['fullCache']);
    }

    public static function toString(ObjectEntry $object): string
    {
        $state = self::state($object);
        if ($state['index'] < 0 || null === $state['cached']) {
            return '';
        }
        $resolved = $state['cached']->resolveIndirect();

        return match ($resolved->type) {
            Variable::TYPE_STRING => $resolved->toString(),
            Variable::TYPE_INTEGER => (string) $resolved->toInt(),
            Variable::TYPE_FLOAT => (string) $resolved->toFloat(),
            Variable::TYPE_BOOLEAN => $resolved->toBool() ? '1' : '',
            Variable::TYPE_NULL => '',
            default => 'Object',
        };
    }

    private static function updateCache(Frame $frame, ObjectEntry $object): void
    {
        $state = &self::$store[$object->id];
        $current = SplDualIteratorStorage::callInner($frame, $state['inner'], 'current');
        $key = SplDualIteratorStorage::callInner($frame, $state['inner'], 'key');
        $state['cached'] = $current->resolveIndirect();
        $state['cachedKey'] = $key->resolveIndirect();
        if (0 !== ($state['flags'] & CachingIteratorBuiltin::FULL_CACHE)) {
            $state['fullCache'][] = true;
        }
    }

    private static function syncInnerPosition(Frame $frame, ObjectEntry $inner, int $wrapperIndex): void
    {
        SplDualIteratorStorage::callInner($frame, $inner, 'rewind');
        for ($i = 0; $i < $wrapperIndex; ++$i) {
            SplDualIteratorStorage::callInner($frame, $inner, 'next');
        }
    }

    /** @return array{inner: ObjectEntry, flags: int, index: int, cached: ?Variable, cachedKey: ?Variable, fullCache: list<true>} */
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
        $object = SplIteratorSupport::receiver(
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
        $inner = SplDualIteratorStorage::resolveIterator(
            $frame->vmContext,
            $frame,
            $frame->calledArgs[1]
        );
        $flags = 0;
        if (isset($frame->calledArgs[2])) {
            $flagsArg = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $flagsArg->type) {
                throw new \TypeError(
                    'CachingIterator::__construct(): Argument #2 ($flags) must be of type int, '
                    .self::typeLabel($flagsArg).' given'
                );
            }
            $flags = $flagsArg->toInt();
        }
        SplDualIteratorStorage::callInner($frame, $inner, 'rewind');
        SplCachingIteratorStorage::init($object, $inner, $flags);
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
        $frame->returnVar->string(SplCachingIteratorStorage::toString($object));
    }
}
