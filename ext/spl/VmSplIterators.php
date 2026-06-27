<?php

declare(strict_types=1);

namespace PHPCompiler\ext\spl;

use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCfg\Func as CfgFunc;

/**
 * SPL iterator interfaces and classes (php-src ext/spl/spl_iterators.c; issue #6593).
 */
final class VmSplIterators
{
    public static function register(Context $ctx): void
    {
        self::registerRecursiveIteratorInterface($ctx);
        self::registerOuterIteratorInterface($ctx);
        self::registerInternalIterator($ctx);
        EmptyIteratorBuiltin::registerClass($ctx);
        ArrayIteratorBuiltin::registerClass($ctx);
        RecursiveArrayIteratorBuiltin::registerClass($ctx);
        RecursiveCallbackFilterIteratorBuiltin::registerClass($ctx);
    }

    /**
     * InternalIterator — SPL internal iterator base (php-src ext/spl/spl_iterators.c; #11781).
     */
    private static function registerInternalIterator(Context $ctx): void
    {
        if (isset($ctx->classes['internaliterator'])) {
            return;
        }

        $entry = new ClassEntry('InternalIterator');
        if (isset($ctx->classes['iterator'])) {
            $entry->interfaces[] = 'iterator';
        }

        $priv = CfgFunc::FLAG_PRIVATE;
        $entry->constructor = new InternalIteratorConstruct();
        $entry->methods['__construct'] = $entry->constructor;
        $entry->methodVisibility['__construct'] = $priv;

        $pub = CfgFunc::FLAG_PUBLIC;
        foreach (['current', 'key', 'next', 'valid', 'rewind'] as $method) {
            $handler = match ($method) {
                'current' => new InternalIteratorCurrent(),
                'key' => new InternalIteratorKey(),
                'next' => new InternalIteratorNext(),
                'valid' => new InternalIteratorValid(),
                'rewind' => new InternalIteratorRewind(),
            };
            $entry->methods[$method] = $handler;
            $entry->methodVisibility[$method] = $pub;
        }

        $ctx->classes['internaliterator'] = $entry;
    }

    private static function registerRecursiveIteratorInterface(Context $ctx): void
    {
        if (isset($ctx->classes['recursiveiterator'])) {
            return;
        }

        $entry = new ClassEntry('RecursiveIterator');
        $entry->isInterface = true;
        if (isset($ctx->classes['iterator'])) {
            $entry->interfaces[] = 'iterator';
        }
        $ctx->classes['recursiveiterator'] = $entry;
    }

    private static function registerOuterIteratorInterface(Context $ctx): void
    {
        if (isset($ctx->classes['outeriterator'])) {
            return;
        }

        $entry = new ClassEntry('OuterIterator');
        $entry->isInterface = true;
        if (isset($ctx->classes['traversable'])) {
            $entry->interfaces[] = 'traversable';
        }
        $ctx->classes['outeriterator'] = $entry;
    }
}

final class InternalIteratorConstruct extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        SplIteratorSupport::receiver($frame, 'internaliterator', 'InternalIterator::__construct()');
    }
}

final class InternalIteratorCurrent extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('current');
    }

    public function execute(Frame $frame): void
    {
        SplIteratorSupport::receiver($frame, 'internaliterator', 'InternalIterator::current()');
        throw new \LogicException('InternalIterator::current() cannot be called');
    }
}

final class InternalIteratorKey extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('key');
    }

    public function execute(Frame $frame): void
    {
        SplIteratorSupport::receiver($frame, 'internaliterator', 'InternalIterator::key()');
        throw new \LogicException('InternalIterator::key() cannot be called');
    }
}

final class InternalIteratorNext extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('next');
    }

    public function execute(Frame $frame): void
    {
        SplIteratorSupport::receiver($frame, 'internaliterator', 'InternalIterator::next()');
    }
}

final class InternalIteratorValid extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('valid');
    }

    public function execute(Frame $frame): void
    {
        SplIteratorSupport::receiver($frame, 'internaliterator', 'InternalIterator::valid()');
        SplIteratorSupport::setReturnBool($frame, false);
    }
}

final class InternalIteratorRewind extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('rewind');
    }

    public function execute(Frame $frame): void
    {
        SplIteratorSupport::receiver($frame, 'internaliterator', 'InternalIterator::rewind()');
    }
}
