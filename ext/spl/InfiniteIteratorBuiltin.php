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
 * InfiniteIterator / NoRewindIterator — IteratorIterator wrappers (php-src ext/spl/spl_iterators.c; #13170).
 */
final class InfiniteIteratorBuiltin
{
    public const CLASS_LC = 'infiniteiterator';

    public static function registerClass(Context $ctx): void
    {
        IteratorIteratorBuiltin::registerClass($ctx);

        if (isset($ctx->classes[self::CLASS_LC]) && self::classIsComplete($ctx->classes[self::CLASS_LC])) {
            return;
        }

        $pub = CfgFunc::FLAG_PUBLIC;
        $entry = isset($ctx->classes[self::CLASS_LC])
            ? $ctx->classes[self::CLASS_LC]
            : new ClassEntry('InfiniteIterator');
        $entry->parentLc = IteratorIteratorBuiltin::CLASS_LC;
        // Zend rematerialized flattened ce->interfaces (#25798).
        $entry->interfaces = [];
        foreach (['iterator', 'traversable', 'outeriterator'] as $iface) {
            if (isset($ctx->classes[$iface])) {
                $entry->interfaces[] = $iface;
            }
        }

        $entry->constructor = new InfiniteIteratorConstruct();
        $entry->methods['__construct'] = $entry->constructor;
        $entry->methodVisibility['__construct'] = $pub;
        foreach ([
            'rewind' => InfiniteIteratorRewind::class,
            'valid' => InfiniteIteratorValid::class,
            'current' => InfiniteIteratorCurrent::class,
            'key' => InfiniteIteratorKey::class,
            'next' => InfiniteIteratorNext::class,
            'getinneriterator' => InfiniteIteratorGetInnerIterator::class,
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
        return isset($entry->methods['rewind'], $entry->methods['valid'], $entry->methods['next']);
    }
}

final class NoRewindIteratorBuiltin
{
    public const CLASS_LC = 'norewinditerator';

    public static function registerClass(Context $ctx): void
    {
        IteratorIteratorBuiltin::registerClass($ctx);

        if (isset($ctx->classes[self::CLASS_LC]) && self::classIsComplete($ctx->classes[self::CLASS_LC])) {
            return;
        }

        $pub = CfgFunc::FLAG_PUBLIC;
        $entry = isset($ctx->classes[self::CLASS_LC])
            ? $ctx->classes[self::CLASS_LC]
            : new ClassEntry('NoRewindIterator');
        $entry->parentLc = IteratorIteratorBuiltin::CLASS_LC;
        // Zend rematerialized flattened ce->interfaces (#25798).
        $entry->interfaces = [];
        foreach (['iterator', 'traversable', 'outeriterator'] as $iface) {
            if (isset($ctx->classes[$iface])) {
                $entry->interfaces[] = $iface;
            }
        }

        $entry->constructor = new NoRewindIteratorConstruct();
        $entry->methods['__construct'] = $entry->constructor;
        $entry->methodVisibility['__construct'] = $pub;
        foreach ([
            'rewind' => NoRewindIteratorRewind::class,
            'valid' => NoRewindIteratorValid::class,
            'current' => NoRewindIteratorCurrent::class,
            'key' => NoRewindIteratorKey::class,
            'next' => NoRewindIteratorNext::class,
            'getinneriterator' => NoRewindIteratorGetInnerIterator::class,
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
        return isset($entry->methods['rewind'], $entry->methods['valid'], $entry->methods['__construct']);
    }
}

final class InfiniteIteratorConstruct extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        // php-src zim_InfiniteIterator___construct — exactly one iterator (#31071).
        $this->requireExactUserArgCount($frame, 'InfiniteIterator::__construct', 1);
        $object = SplIteratorSupport::receiver(
            $frame,
            InfiniteIteratorBuiltin::CLASS_LC,
            'InfiniteIterator::__construct()'
        );
        if (null === $frame->vmContext) {
            throw new \LogicException('InfiniteIterator::__construct() requires VM context');
        }
        $inner = SplDualIteratorStorage::resolveIterator(
            $frame->vmContext,
            $frame,
            $frame->calledArgs[1],
            'InfiniteIterator::__construct',
            'Iterator'
        );
        SplDualIteratorStorage::initSimple($object, $inner);
    }
}

final class NoRewindIteratorConstruct extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        // php-src zim_NoRewindIterator___construct — exactly one iterator (#31071).
        $this->requireExactUserArgCount($frame, 'NoRewindIterator::__construct', 1);
        $object = SplIteratorSupport::receiver(
            $frame,
            NoRewindIteratorBuiltin::CLASS_LC,
            'NoRewindIterator::__construct()'
        );
        if (null === $frame->vmContext) {
            throw new \LogicException('NoRewindIterator::__construct() requires VM context');
        }
        $inner = SplDualIteratorStorage::resolveIterator(
            $frame->vmContext,
            $frame,
            $frame->calledArgs[1],
            'NoRewindIterator::__construct',
            'Iterator'
        );
        SplDualIteratorStorage::initNoRewind($object, $inner);
    }
}

final class InfiniteIteratorRewind extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('rewind');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            InfiniteIteratorBuiltin::CLASS_LC,
            'InfiniteIterator::rewind()'
        );
        SplDualIteratorStorage::rewindSimple($frame, $object);
    }
}

final class InfiniteIteratorValid extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('valid');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            InfiniteIteratorBuiltin::CLASS_LC,
            'InfiniteIterator::valid()'
        );
        SplIteratorSupport::setReturnBool(
            $frame,
            SplDualIteratorStorage::validSimple($frame, $object)
        );
    }
}

final class InfiniteIteratorCurrent extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('current');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            InfiniteIteratorBuiltin::CLASS_LC,
            'InfiniteIterator::current()'
        );
        SplIteratorSupport::copyReturnFrom(
            $frame,
            SplDualIteratorStorage::currentSimple($frame, $object)
        );
    }
}

final class InfiniteIteratorKey extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('key');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            InfiniteIteratorBuiltin::CLASS_LC,
            'InfiniteIterator::key()'
        );
        SplIteratorSupport::copyReturnFrom(
            $frame,
            SplDualIteratorStorage::keySimple($frame, $object)
        );
    }
}

final class InfiniteIteratorNext extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('next');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            InfiniteIteratorBuiltin::CLASS_LC,
            'InfiniteIterator::next()'
        );
        SplDualIteratorStorage::nextSimple($frame, $object);
        if (!SplDualIteratorStorage::validSimple($frame, $object)) {
            SplDualIteratorStorage::rewindSimple($frame, $object);
        }
    }
}

final class InfiniteIteratorGetInnerIterator extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getInnerIterator');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            InfiniteIteratorBuiltin::CLASS_LC,
            'InfiniteIterator::getInnerIterator()'
        );
        // Inherited zim_IteratorIterator_getInnerIterator — ACE cites IteratorIterator (#30949).
        $this->requireExactUserArgCount($frame, 'IteratorIterator::getInnerIterator', 0);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->object(SplDualIteratorStorage::inner($object));
    }
}

final class NoRewindIteratorRewind extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('rewind');
    }

    public function execute(Frame $frame): void
    {
        // php-src spl_norewind_iterator_rewind — no-op; inner position preserved (#13170).
    }
}

final class NoRewindIteratorValid extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('valid');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            NoRewindIteratorBuiltin::CLASS_LC,
            'NoRewindIterator::valid()'
        );
        SplIteratorSupport::setReturnBool(
            $frame,
            SplDualIteratorStorage::validSimple($frame, $object)
        );
    }
}

final class NoRewindIteratorCurrent extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('current');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            NoRewindIteratorBuiltin::CLASS_LC,
            'NoRewindIterator::current()'
        );
        SplIteratorSupport::copyReturnFrom(
            $frame,
            SplDualIteratorStorage::currentSimple($frame, $object)
        );
    }
}

final class NoRewindIteratorKey extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('key');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            NoRewindIteratorBuiltin::CLASS_LC,
            'NoRewindIterator::key()'
        );
        SplIteratorSupport::copyReturnFrom(
            $frame,
            SplDualIteratorStorage::keySimple($frame, $object)
        );
    }
}

final class NoRewindIteratorNext extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('next');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            NoRewindIteratorBuiltin::CLASS_LC,
            'NoRewindIterator::next()'
        );
        SplDualIteratorStorage::nextSimple($frame, $object);
    }
}

final class NoRewindIteratorGetInnerIterator extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getInnerIterator');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            NoRewindIteratorBuiltin::CLASS_LC,
            'NoRewindIterator::getInnerIterator()'
        );
        // Inherited zim_IteratorIterator_getInnerIterator — ACE cites IteratorIterator (#30949).
        $this->requireExactUserArgCount($frame, 'IteratorIterator::getInnerIterator', 0);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->object(SplDualIteratorStorage::inner($object));
    }
}
