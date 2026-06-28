<?php

declare(strict_types=1);

namespace PHPCompiler\ext\spl;

use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;
use PHPCompiler\VM\WeakRefSupport;
use PHPCfg\Func as CfgFunc;

/**
 * SplObjectStorage — object-to-data map (php-src ext/spl/spl_observer.c; #12962).
 */
final class SplObjectStorageBuiltin
{
    public const CLASS_LC = 'splobjectstorage';

    /**
     * @var array<int, array{
     *   entries: array<string, Variable>,
     *   order: list<string>
     * }>
     */
    private static array $store = [];

    public static function registerClass(Context $ctx): void
    {
        if (isset($ctx->classes[self::CLASS_LC]) && self::classIsComplete($ctx->classes[self::CLASS_LC])) {
            return;
        }

        $pub = CfgFunc::FLAG_PUBLIC;
        $entry = isset($ctx->classes[self::CLASS_LC])
            ? $ctx->classes[self::CLASS_LC]
            : new ClassEntry('SplObjectStorage');
        foreach (['Countable', 'Iterator', 'Traversable', 'Serializable', 'ArrayAccess'] as $iface) {
            if (isset($ctx->classes[strtolower($iface)])
                && !\in_array($iface, $entry->interfaces, true)) {
                $entry->interfaces[] = $iface;
            }
        }

        $entry->constructor = new SplObjectStorageConstruct();
        $entry->methods['__construct'] = $entry->constructor;
        $entry->methodVisibility['__construct'] = $pub;
        foreach ([
            'attach' => SplObjectStorageAttach::class,
            'contains' => SplObjectStorageContains::class,
            'count' => SplObjectStorageCount::class,
            'offsetget' => SplObjectStorageOffsetGet::class,
            'offsetset' => SplObjectStorageOffsetSet::class,
            'offsetexists' => SplObjectStorageOffsetExists::class,
            'offsetunset' => SplObjectStorageOffsetUnset::class,
        ] as $lc => $class) {
            $entry->methods[$lc] = new $class();
            $entry->methodVisibility[$lc] = $pub;
        }
        $entry->methodNames['offsetget'] = 'offsetGet';
        $entry->methodNames['offsetset'] = 'offsetSet';
        $entry->methodNames['offsetexists'] = 'offsetExists';
        $entry->methodNames['offsetunset'] = 'offsetUnset';

        $entry->isInternal = true;
        $ctx->classes[self::CLASS_LC] = $entry;
    }

    private static function classIsComplete(ClassEntry $entry): bool
    {
        return isset($entry->methods['offsetset'], $entry->methods['attach']);
    }

    public static function init(ObjectEntry $object): void
    {
        self::$store[$object->id] = ['entries' => [], 'order' => []];
    }

    /** @return array{entries: array<string, Variable>, order: list<string>} */
    private static function state(ObjectEntry $object): array
    {
        if (!isset(self::$store[$object->id])) {
            self::init($object);
        }

        return self::$store[$object->id];
    }

    public static function attach(ObjectEntry $storage, Variable $object, ?Variable $info = null): void
    {
        $key = WeakRefSupport::objectKey($object);
        $state = self::state($storage);
        if (!isset($state['entries'][$key])) {
            self::$store[$storage->id]['order'][] = $key;
        }
        if (null === $info) {
            $stored = new Variable(Variable::TYPE_NULL);
            $stored->null();
        } else {
            $stored = new Variable();
            $stored->copyFrom($info->resolveIndirect());
        }
        self::$store[$storage->id]['entries'][$key] = $stored;
    }

    public static function contains(ObjectEntry $storage, Variable $object): bool
    {
        $key = WeakRefSupport::objectKey($object);

        return isset(self::state($storage)['entries'][$key]);
    }

    public static function count(ObjectEntry $storage): int
    {
        return \count(self::state($storage)['entries']);
    }

    public static function offsetGet(ObjectEntry $storage, Variable $object): Variable
    {
        $key = WeakRefSupport::objectKey($object);
        $entries = self::state($storage)['entries'];
        if (!isset($entries[$key])) {
            throw new \UnexpectedValueException('Object not found');
        }
        $out = new Variable();
        $out->copyFrom($entries[$key]);

        return $out;
    }

    public static function offsetSet(ObjectEntry $storage, Variable $object, Variable $value): void
    {
        self::attach($storage, $object, $value);
    }

    public static function offsetExists(ObjectEntry $storage, Variable $object): bool
    {
        return self::contains($storage, $object);
    }

    public static function offsetUnset(ObjectEntry $storage, Variable $object): void
    {
        $key = WeakRefSupport::objectKey($object);
        if (!isset(self::state($storage)['entries'][$key])) {
            return;
        }
        unset(self::$store[$storage->id]['entries'][$key]);
        self::$store[$storage->id]['order'] = array_values(
            array_filter(
                self::$store[$storage->id]['order'],
                static fn (string $storedKey): bool => $storedKey !== $key
            )
        );
    }
}

final class SplObjectStorageConstruct extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiver(
            $frame,
            SplObjectStorageBuiltin::CLASS_LC,
            'SplObjectStorage::__construct()'
        );
        SplObjectStorageBuiltin::init($object);
    }
}

final class SplObjectStorageAttach extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('attach');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiver(
            $frame,
            SplObjectStorageBuiltin::CLASS_LC,
            'SplObjectStorage::attach()'
        );
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError(
                'SplObjectStorage::attach() expects at least 1 argument, '
                .(\count($frame->calledArgs) - 1).' given'
            );
        }
        $info = isset($frame->calledArgs[2]) ? $frame->calledArgs[2] : null;
        SplObjectStorageBuiltin::attach($object, $frame->calledArgs[1], $info);
    }
}

final class SplObjectStorageContains extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('contains');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiver(
            $frame,
            SplObjectStorageBuiltin::CLASS_LC,
            'SplObjectStorage::contains()'
        );
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError(
                'SplObjectStorage::contains() expects exactly 1 argument, '
                .(\count($frame->calledArgs) - 1).' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool(
            SplObjectStorageBuiltin::contains($object, $frame->calledArgs[1])
        );
    }
}

final class SplObjectStorageCount extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('count');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiver(
            $frame,
            SplObjectStorageBuiltin::CLASS_LC,
            'SplObjectStorage::count()'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int(SplObjectStorageBuiltin::count($object));
    }
}

final class SplObjectStorageOffsetGet extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('offsetGet');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiver(
            $frame,
            SplObjectStorageBuiltin::CLASS_LC,
            'SplObjectStorage::offsetGet()'
        );
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError(
                'SplObjectStorage::offsetGet() expects exactly 1 argument, '
                .(\count($frame->calledArgs) - 1).' given'
            );
        }
        SplIteratorSupport::copyReturnFrom(
            $frame,
            SplObjectStorageBuiltin::offsetGet($object, $frame->calledArgs[1])
        );
    }
}

final class SplObjectStorageOffsetSet extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('offsetSet');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiver(
            $frame,
            SplObjectStorageBuiltin::CLASS_LC,
            'SplObjectStorage::offsetSet()'
        );
        if (\count($frame->calledArgs) < 3) {
            throw new \ArgumentCountError(
                'SplObjectStorage::offsetSet() expects exactly 2 arguments, '
                .(\count($frame->calledArgs) - 1).' given'
            );
        }
        SplObjectStorageBuiltin::offsetSet($object, $frame->calledArgs[1], $frame->calledArgs[2]);
    }
}

final class SplObjectStorageOffsetExists extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('offsetExists');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiver(
            $frame,
            SplObjectStorageBuiltin::CLASS_LC,
            'SplObjectStorage::offsetExists()'
        );
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError(
                'SplObjectStorage::offsetExists() expects exactly 1 argument, '
                .(\count($frame->calledArgs) - 1).' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool(
            SplObjectStorageBuiltin::offsetExists($object, $frame->calledArgs[1])
        );
    }
}

final class SplObjectStorageOffsetUnset extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('offsetUnset');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiver(
            $frame,
            SplObjectStorageBuiltin::CLASS_LC,
            'SplObjectStorage::offsetUnset()'
        );
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError(
                'SplObjectStorage::offsetUnset() expects exactly 1 argument, '
                .(\count($frame->calledArgs) - 1).' given'
            );
        }
        SplObjectStorageBuiltin::offsetUnset($object, $frame->calledArgs[1]);
    }
}
