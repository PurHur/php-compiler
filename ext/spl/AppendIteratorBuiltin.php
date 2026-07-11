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
        foreach (['OuterIterator', 'Traversable', 'Iterator'] as $iface) {
            if (isset($ctx->classes[strtolower($iface)])
                && !\in_array($iface, $entry->interfaces, true)) {
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
        ] as $lc => $class) {
            $entry->methods[$lc] = new $class();
            $entry->methodVisibility[$lc] = $pub;
        }
        $entry->methodNames['getinneriterator'] = 'getInnerIterator';

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
        self::$store[$object->id]['iterators'][] = $inner;
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
