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
 * FilterIterator — user accept() filtering over inner iterator (php-src ext/spl/spl_iterators.c; #13178).
 */
final class FilterIteratorBuiltin
{
    public const CLASS_LC = 'filteriterator';

    public static function registerClass(Context $ctx): void
    {
        IteratorIteratorBuiltin::registerClass($ctx);

        if (isset($ctx->classes[self::CLASS_LC]) && self::classIsComplete($ctx->classes[self::CLASS_LC])) {
            return;
        }

        $pub = CfgFunc::FLAG_PUBLIC;
        $prot = CfgFunc::FLAG_PROTECTED;
        $entry = isset($ctx->classes[self::CLASS_LC])
            ? $ctx->classes[self::CLASS_LC]
            : new ClassEntry('FilterIterator');
        $entry->parentLc = IteratorIteratorBuiltin::CLASS_LC;
        foreach (['OuterIterator', 'Traversable', 'Iterator'] as $iface) {
            if (isset($ctx->classes[strtolower($iface)])
                && !\in_array($iface, $entry->interfaces, true)) {
                $entry->interfaces[] = $iface;
            }
        }

        $entry->constructor = new FilterIteratorConstruct();
        $entry->methods['__construct'] = $entry->constructor;
        $entry->methodVisibility['__construct'] = $pub;
        $entry->methods['accept'] = new FilterIteratorAccept();
        $entry->methodVisibility['accept'] = $prot;
        foreach ([
            'rewind' => FilterIteratorRewind::class,
            'valid' => FilterIteratorValid::class,
            'current' => FilterIteratorCurrent::class,
            'key' => FilterIteratorKey::class,
            'next' => FilterIteratorNext::class,
            'getinneriterator' => FilterIteratorGetInnerIterator::class,
        ] as $lc => $class) {
            $entry->methods[$lc] = new $class();
            $entry->methodVisibility[$lc] = $pub;
        }
        $entry->methodNames['getinneriterator'] = 'getInnerIterator';

        $entry->isInternal = true;
        $ctx->classes[self::CLASS_LC] = $entry;

        RecursiveFilterIteratorBuiltin::registerClass($ctx);
    }

    private static function classIsComplete(ClassEntry $entry): bool
    {
        return isset($entry->methods['rewind'], $entry->methods['accept'], $entry->methods['__construct']);
    }

    public static function fetch(Frame $frame, ObjectEntry $object): void
    {
        $inner = SplDualIteratorStorage::inner($object);
        while (true) {
            $valid = SplDualIteratorStorage::callInner($frame, $inner, 'valid')->resolveIndirect();
            if (Variable::TYPE_BOOLEAN !== $valid->type || !$valid->toBool()) {
                return;
            }
            if (self::callAccept($frame, $object)) {
                return;
            }
            SplDualIteratorStorage::callInner($frame, $inner, 'next');
        }
    }

    public static function callAccept(Frame $frame, ObjectEntry $object): bool
    {
        $result = self::vm($frame)->invokeInstanceMethod($object, 'accept')->resolveIndirect();

        return Variable::TYPE_BOOLEAN === $result->type && $result->toBool();
    }

    private static function vm(Frame $frame): \PHPCompiler\VM
    {
        if (null === $frame->vmContext || null === $frame->vmContext->runtime) {
            throw new \LogicException('FilterIterator requires VM runtime');
        }

        return $frame->vmContext->runtime->vm;
    }
}

/** RecursiveFilterIterator shares FilterIterator constructor + fetch (#13178). */
final class RecursiveFilterIteratorBuiltin
{
    public const CLASS_LC = 'recursivefilteriterator';

    public static function registerClass(Context $ctx): void
    {
        if (isset($ctx->classes[self::CLASS_LC]) && isset($ctx->classes[self::CLASS_LC]->methods['__construct'])) {
            return;
        }

        $pub = CfgFunc::FLAG_PUBLIC;
        $entry = isset($ctx->classes[self::CLASS_LC])
            ? $ctx->classes[self::CLASS_LC]
            : new ClassEntry('RecursiveFilterIterator');
        $entry->parentLc = FilterIteratorBuiltin::CLASS_LC;
        foreach (['OuterIterator', 'Traversable', 'Iterator', 'RecursiveIterator'] as $iface) {
            if (isset($ctx->classes[strtolower($iface)])
                && !\in_array($iface, $entry->interfaces, true)) {
                $entry->interfaces[] = $iface;
            }
        }

        $entry->constructor = new RecursiveFilterIteratorConstruct();
        $entry->methods['__construct'] = $entry->constructor;
        $entry->methodVisibility['__construct'] = $pub;

        $entry->isInternal = true;
        $ctx->classes[self::CLASS_LC] = $entry;
    }
}

final class FilterIteratorConstruct extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            FilterIteratorBuiltin::CLASS_LC,
            'FilterIterator::__construct()'
        );
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError(
                'FilterIterator::__construct() expects exactly 1 argument, '
                .(\count($frame->calledArgs) - 1).' given'
            );
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('FilterIterator::__construct() requires VM context');
        }
        $inner = SplDualIteratorStorage::resolveIterator(
            $frame->vmContext,
            $frame,
            $frame->calledArgs[1]
        );
        SplDualIteratorStorage::initSimple($object, $inner);
    }
}

final class RecursiveFilterIteratorConstruct extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            RecursiveFilterIteratorBuiltin::CLASS_LC,
            'RecursiveFilterIterator::__construct()'
        );
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError(
                'RecursiveFilterIterator::__construct() expects exactly 1 argument, '
                .(\count($frame->calledArgs) - 1).' given'
            );
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('RecursiveFilterIterator::__construct() requires VM context');
        }
        $inner = SplDualIteratorStorage::resolveRecursiveIterator(
            $frame->vmContext,
            $frame,
            $frame->calledArgs[1]
        );
        SplDualIteratorStorage::initSimple($object, $inner);
    }
}

final class FilterIteratorAccept extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('accept');
    }

    public function execute(Frame $frame): void
    {
        SplIteratorSupport::receiverIsA(
            $frame,
            FilterIteratorBuiltin::CLASS_LC,
            'FilterIterator::accept()'
        );
        throw new \BadMethodCallException('FilterIterator::accept() must be implemented in a subclass');
    }
}

final class FilterIteratorRewind extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('rewind');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            FilterIteratorBuiltin::CLASS_LC,
            'FilterIterator::rewind()'
        );
        SplDualIteratorStorage::rewindSimple($frame, $object);
        FilterIteratorBuiltin::fetch($frame, $object);
    }
}

final class FilterIteratorNext extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('next');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            FilterIteratorBuiltin::CLASS_LC,
            'FilterIterator::next()'
        );
        $inner = SplDualIteratorStorage::inner($object);
        SplDualIteratorStorage::callInner($frame, $inner, 'next');
        FilterIteratorBuiltin::fetch($frame, $object);
    }
}

final class FilterIteratorValid extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('valid');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            FilterIteratorBuiltin::CLASS_LC,
            'FilterIterator::valid()'
        );
        SplIteratorSupport::setReturnBool($frame, SplDualIteratorStorage::validSimple($frame, $object));
    }
}

final class FilterIteratorCurrent extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('current');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            FilterIteratorBuiltin::CLASS_LC,
            'FilterIterator::current()'
        );
        SplIteratorSupport::copyReturnFrom($frame, SplDualIteratorStorage::currentSimple($frame, $object));
    }
}

final class FilterIteratorKey extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('key');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            FilterIteratorBuiltin::CLASS_LC,
            'FilterIterator::key()'
        );
        SplIteratorSupport::copyReturnFrom($frame, SplDualIteratorStorage::keySimple($frame, $object));
    }
}

final class FilterIteratorGetInnerIterator extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getInnerIterator');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            FilterIteratorBuiltin::CLASS_LC,
            'FilterIterator::getInnerIterator()'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $inner = SplDualIteratorStorage::inner($object);
        $frame->returnVar->object($inner);
    }
}
