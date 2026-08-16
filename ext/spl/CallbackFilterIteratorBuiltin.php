<?php

declare(strict_types=1);

namespace PHPCompiler\ext\spl;

use PHPCompiler\ext\standard\VmCallable;
use PHPCompiler\ext\standard\VmClosureCall;
use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;
use PHPCfg\Func as CfgFunc;

/**
 * CallbackFilterIterator — SPL callback filter (php-src ext/spl/spl_iterators.c; #13211).
 */
final class CallbackFilterIteratorBuiltin
{
    public const CLASS_LC = 'callbackfilteriterator';

    /** @var array<int, array{0: Variable, 1: ?\PHPCompiler\VM\ClosureState}> */
    private static array $callbacks = [];

    public static function registerClass(Context $ctx): void
    {
        FilterIteratorBuiltin::registerClass($ctx);

        if (isset($ctx->classes[self::CLASS_LC]) && self::classIsComplete($ctx->classes[self::CLASS_LC])) {
            return;
        }

        $pub = CfgFunc::FLAG_PUBLIC;
        $entry = isset($ctx->classes[self::CLASS_LC])
            ? $ctx->classes[self::CLASS_LC]
            : new ClassEntry('CallbackFilterIterator');
        $entry->parentLc = FilterIteratorBuiltin::CLASS_LC;
        foreach (['OuterIterator', 'Traversable', 'Iterator'] as $iface) {
            if (isset($ctx->classes[strtolower($iface)])
                && !\in_array($iface, $entry->interfaces, true)) {
                $entry->interfaces[] = $iface;
            }
        }

        $entry->constructor = new CallbackFilterIteratorConstruct();
        $entry->methods['__construct'] = $entry->constructor;
        $entry->methodVisibility['__construct'] = $pub;
        // php-src — public function accept(): bool (#28560).
        $entry->methods['accept'] = new CallbackFilterIteratorAccept();
        $entry->methodVisibility['accept'] = $pub;
        foreach ([
            'rewind' => CallbackFilterIteratorRewind::class,
            'valid' => CallbackFilterIteratorValid::class,
            'current' => CallbackFilterIteratorCurrent::class,
            'key' => CallbackFilterIteratorKey::class,
            'next' => CallbackFilterIteratorNext::class,
            'getinneriterator' => CallbackFilterIteratorGetInnerIterator::class,
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
        return isset($entry->methods['rewind'], $entry->methods['accept'], $entry->methods['__construct']);
    }

    public static function setCallback(ObjectEntry $object, Variable $callback): void
    {
        self::$callbacks[$object->id] = SplIteratorSupport::pinCallback($callback);
    }

    public static function callback(ObjectEntry $object): Variable
    {
        if (!isset(self::$callbacks[$object->id])) {
            throw new \LogicException('CallbackFilterIterator callback missing');
        }

        return self::$callbacks[$object->id][0];
    }

    public static function callbackClosure(ObjectEntry $object): ?\PHPCompiler\VM\ClosureState
    {
        if (!isset(self::$callbacks[$object->id])) {
            throw new \LogicException('CallbackFilterIterator callback missing');
        }

        return self::$callbacks[$object->id][1];
    }

    public static function callAccept(Frame $frame, ObjectEntry $object): bool
    {
        if (null === $frame->vmContext) {
            throw new \LogicException('CallbackFilterIterator requires VM context');
        }
        $inner = SplDualIteratorStorage::inner($object);
        $current = SplDualIteratorStorage::callInner($frame, $inner, 'current');
        $key = SplDualIteratorStorage::callInner($frame, $inner, 'key');
        $filter = new Variable();
        $filter->object($object);
        $closure = self::callbackClosure($object);
        if (null !== $closure) {
            return VmClosureCall::invoke(
                $frame->vmContext,
                $closure,
                $current,
                $key,
                $filter
            )->resolveIndirect()->toBool();
        }

        return VmCallable::invoke(
            $frame->vmContext,
            self::callback($object),
            $current,
            $key,
            $filter
        )->resolveIndirect()->toBool();
    }
}

final class CallbackFilterIteratorConstruct extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            CallbackFilterIteratorBuiltin::CLASS_LC,
            'CallbackFilterIterator::__construct()'
        );
        if (\count($frame->calledArgs) < 3) {
            throw new \ArgumentCountError(
                'CallbackFilterIterator::__construct() expects exactly 2 arguments, '
                .(\count($frame->calledArgs) - 1).' given'
            );
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('CallbackFilterIterator::__construct() requires VM context');
        }
        $inner = SplDualIteratorStorage::resolveIterator(
            $frame->vmContext,
            $frame,
            $frame->calledArgs[1],
            'CallbackFilterIterator::__construct',
            'Iterator'
        );
        SplDualIteratorStorage::initSimple($object, $inner);
        CallbackFilterIteratorBuiltin::setCallback($object, $frame->calledArgs[2]);
    }
}

final class CallbackFilterIteratorAccept extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('accept');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            CallbackFilterIteratorBuiltin::CLASS_LC,
            'CallbackFilterIterator::accept()'
        );
        SplIteratorSupport::setReturnBool($frame, CallbackFilterIteratorBuiltin::callAccept($frame, $object));
    }
}

final class CallbackFilterIteratorRewind extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('rewind');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            CallbackFilterIteratorBuiltin::CLASS_LC,
            'CallbackFilterIterator::rewind()'
        );
        SplDualIteratorStorage::rewindSimple($frame, $object);
        FilterIteratorBuiltin::fetch($frame, $object);
    }
}

final class CallbackFilterIteratorNext extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('next');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            CallbackFilterIteratorBuiltin::CLASS_LC,
            'CallbackFilterIterator::next()'
        );
        $inner = SplDualIteratorStorage::inner($object);
        SplDualIteratorStorage::callInner($frame, $inner, 'next');
        FilterIteratorBuiltin::fetch($frame, $object);
    }
}

final class CallbackFilterIteratorValid extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('valid');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            CallbackFilterIteratorBuiltin::CLASS_LC,
            'CallbackFilterIterator::valid()'
        );
        SplIteratorSupport::setReturnBool($frame, SplDualIteratorStorage::validSimple($frame, $object));
    }
}

final class CallbackFilterIteratorCurrent extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('current');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            CallbackFilterIteratorBuiltin::CLASS_LC,
            'CallbackFilterIterator::current()'
        );
        SplIteratorSupport::copyReturnFrom($frame, SplDualIteratorStorage::currentSimple($frame, $object));
    }
}

final class CallbackFilterIteratorKey extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('key');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            CallbackFilterIteratorBuiltin::CLASS_LC,
            'CallbackFilterIterator::key()'
        );
        SplIteratorSupport::copyReturnFrom($frame, SplDualIteratorStorage::keySimple($frame, $object));
    }
}

final class CallbackFilterIteratorGetInnerIterator extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getInnerIterator');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            CallbackFilterIteratorBuiltin::CLASS_LC,
            'CallbackFilterIterator::getInnerIterator()'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $inner = SplDualIteratorStorage::inner($object);
        $frame->returnVar->object($inner);
    }
}
