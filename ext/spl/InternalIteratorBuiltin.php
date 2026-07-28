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
 * InternalIterator — opaque IteratorAggregate result (php-src Zend/zend_interfaces.c; #11781, #21466).
 *
 * Not user-constructible (private __construct). Created by extension getIterator() factories
 * with either a snapshot HashTable or a live handler (DOM NodeList; #21930) so foreach /
 * Iterator methods match Zend class identity.
 */
final class InternalIteratorBuiltin
{
    public const CLASS_LC = 'internaliterator';

    /** @var array<int, InternalIteratorLiveHandler> */
    private static array $liveHandlers = [];

    public static function registerClass(Context $ctx): void
    {
        if (isset($ctx->classes[self::CLASS_LC])) {
            return;
        }

        $entry = new ClassEntry('InternalIterator');
        $entry->isInternal = true;
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

        $ctx->classes[self::CLASS_LC] = $entry;
    }

    /**
     * Factory used by DOM/intl IteratorAggregate getIterator() snapshots (#21466).
     */
    public static function fromTable(Context $ctx, HashTable $table): ObjectEntry
    {
        $class = $ctx->classes[self::CLASS_LC] ?? null;
        if (null === $class) {
            throw new \LogicException('InternalIterator is not registered in this compiler build');
        }
        $entry = new ObjectEntry($class);
        $entry->constructed = true;
        self::init($entry, $table);

        return $entry;
    }

    /**
     * Live-collection factory (DOMNodeList getIterator; php-src nodelist.c; #21930).
     */
    public static function fromLiveHandler(Context $ctx, InternalIteratorLiveHandler $handler): ObjectEntry
    {
        $class = $ctx->classes[self::CLASS_LC] ?? null;
        if (null === $class) {
            throw new \LogicException('InternalIterator is not registered in this compiler build');
        }
        $entry = new ObjectEntry($class);
        $entry->constructed = true;
        self::$liveHandlers[$entry->id] = $handler;

        return $entry;
    }

    public static function init(ObjectEntry $object, HashTable $table): void
    {
        unset(self::$liveHandlers[$object->id]);
        SplArrayStorage::init($object, $table, 0, null, []);
    }

    public static function isLive(ObjectEntry $object): bool
    {
        return isset(self::$liveHandlers[$object->id]);
    }

    public static function hasBacking(ObjectEntry $object): bool
    {
        return self::isLive($object) || SplArrayStorage::hasState($object);
    }

    public static function rewind(ObjectEntry $object): void
    {
        if (isset(self::$liveHandlers[$object->id])) {
            self::$liveHandlers[$object->id]->rewind();

            return;
        }
        SplArrayStorage::rewindIterator($object);
    }

    public static function next(ObjectEntry $object): void
    {
        if (isset(self::$liveHandlers[$object->id])) {
            self::$liveHandlers[$object->id]->next();

            return;
        }
        SplArrayStorage::nextIterator($object);
    }

    public static function valid(ObjectEntry $object): bool
    {
        if (isset(self::$liveHandlers[$object->id])) {
            return self::$liveHandlers[$object->id]->valid();
        }

        return SplArrayStorage::iteratorValid($object);
    }

    public static function current(ObjectEntry $object): Variable
    {
        if (isset(self::$liveHandlers[$object->id])) {
            return self::$liveHandlers[$object->id]->current();
        }

        return SplArrayStorage::iteratorCurrent($object);
    }

    public static function key(ObjectEntry $object): int|string|Variable|null
    {
        if (isset(self::$liveHandlers[$object->id])) {
            return self::$liveHandlers[$object->id]->key();
        }

        return SplArrayStorage::iteratorKey($object);
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
        // Visibility normally blocks user `new InternalIterator()`; if reached, no-op like php-src.
        SplIteratorSupport::receiver($frame, InternalIteratorBuiltin::CLASS_LC, 'InternalIterator::__construct()');
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
        $object = SplIteratorSupport::receiver(
            $frame,
            InternalIteratorBuiltin::CLASS_LC,
            'InternalIterator::current()'
        );
        if (!InternalIteratorBuiltin::hasBacking($object)) {
            throw new \LogicException('InternalIterator::current() cannot be called');
        }
        SplIteratorSupport::copyReturnFrom($frame, InternalIteratorBuiltin::current($object));
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
        $object = SplIteratorSupport::receiver(
            $frame,
            InternalIteratorBuiltin::CLASS_LC,
            'InternalIterator::key()'
        );
        if (!InternalIteratorBuiltin::hasBacking($object)) {
            throw new \LogicException('InternalIterator::key() cannot be called');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $key = InternalIteratorBuiltin::key($object);
        if ($key instanceof Variable) {
            $frame->returnVar->copyFrom($key->resolveIndirect());

            return;
        }
        if (null === $key) {
            $frame->returnVar->null();
        } elseif (\is_int($key)) {
            $frame->returnVar->int($key);
        } else {
            $frame->returnVar->string((string) $key);
        }
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
        $object = SplIteratorSupport::receiver(
            $frame,
            InternalIteratorBuiltin::CLASS_LC,
            'InternalIterator::next()'
        );
        if (InternalIteratorBuiltin::hasBacking($object)) {
            InternalIteratorBuiltin::next($object);
        }
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
        $object = SplIteratorSupport::receiver(
            $frame,
            InternalIteratorBuiltin::CLASS_LC,
            'InternalIterator::valid()'
        );
        $ok = InternalIteratorBuiltin::hasBacking($object) && InternalIteratorBuiltin::valid($object);
        SplIteratorSupport::setReturnBool($frame, $ok);
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
        $object = SplIteratorSupport::receiver(
            $frame,
            InternalIteratorBuiltin::CLASS_LC,
            'InternalIterator::rewind()'
        );
        if (InternalIteratorBuiltin::hasBacking($object)) {
            InternalIteratorBuiltin::rewind($object);
        }
    }
}
