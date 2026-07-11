<?php

declare(strict_types=1);

namespace PHPCompiler\ext\spl;

use PHPCompiler\ext\standard\VmFsGlob;
use PHPCompiler\ext\standard\VmStreamPath;
use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;
use PHPCfg\Func as CfgFunc;

/**
 * GlobIterator — glob-backed filesystem iterator (php-src ext/spl/spl_directory.c; #13169).
 */
final class GlobIteratorBuiltin
{
    public const CLASS_LC = 'globiterator';

    public static function registerClass(Context $ctx): void
    {
        FilesystemIteratorBuiltin::registerClass($ctx);

        if (isset($ctx->classes[self::CLASS_LC]) && self::classIsComplete($ctx->classes[self::CLASS_LC])) {
            return;
        }

        $pub = CfgFunc::FLAG_PUBLIC;
        $entry = isset($ctx->classes[self::CLASS_LC])
            ? $ctx->classes[self::CLASS_LC]
            : new ClassEntry('GlobIterator');
        $entry->parentLc = FilesystemIteratorBuiltin::CLASS_LC;
        foreach (['Stringable', 'SeekableIterator', 'Traversable', 'Iterator', 'Countable'] as $iface) {
            if (isset($ctx->classes[strtolower($iface)])
                && !\in_array($iface, $entry->interfaces, true)) {
                $entry->interfaces[] = $iface;
            }
        }

        $entry->constructor = new GlobIteratorConstruct();
        $entry->methods['__construct'] = $entry->constructor;
        $entry->methodVisibility['__construct'] = $pub;
        foreach ([
            'rewind' => GlobIteratorRewind::class,
            'valid' => GlobIteratorValid::class,
            'current' => GlobIteratorCurrent::class,
            'key' => GlobIteratorKey::class,
            'next' => GlobIteratorNext::class,
            'count' => GlobIteratorCount::class,
        ] as $lc => $class) {
            $entry->methods[$lc] = new $class();
            $entry->methodVisibility[$lc] = $pub;
        }
        $entry->methodNames['count'] = 'count';

        $entry->isInternal = true;
        $ctx->classes[self::CLASS_LC] = $entry;
    }

    private static function classIsComplete(ClassEntry $entry): bool
    {
        return isset(
            $entry->methods['__construct'],
            $entry->methods['rewind'],
            $entry->methods['count']
        );
    }
}

/** @internal */
final class GlobIteratorStorage
{
    /** @var array<int, array{pattern: string, paths: list<string>, index: int, flags: int}> */
    private static array $store = [];

    public static function open(ObjectEntry $object, string $pattern, int $flags = 0): void
    {
        $result = VmFsGlob::glob($pattern, $flags);
        $paths = false === $result ? [] : array_values($result);

        self::$store[$object->id] = [
            'pattern' => $pattern,
            'paths' => $paths,
            'index' => 0,
            'flags' => $flags,
        ];
        self::syncCurrent($object);
    }

    public static function rewind(ObjectEntry $object): void
    {
        $state = &self::state($object);
        $state['index'] = 0;
        self::syncCurrent($object);
    }

    public static function next(ObjectEntry $object): void
    {
        $state = &self::state($object);
        ++$state['index'];
        self::syncCurrent($object);
    }

    public static function valid(ObjectEntry $object): bool
    {
        $state = self::state($object);

        return isset($state['paths'][$state['index']]);
    }

    public static function key(ObjectEntry $object): int
    {
        return self::state($object)['index'];
    }

    public static function count(ObjectEntry $object): int
    {
        return \count(self::state($object)['paths']);
    }

    public static function pathname(ObjectEntry $object): string
    {
        $state = self::state($object);
        if (!isset($state['paths'][$state['index']])) {
            return $state['pattern'];
        }

        return $state['paths'][$state['index']];
    }

    private static function syncCurrent(ObjectEntry $object): void
    {
        if (self::valid($object)) {
            SplFileInfoStorage::init($object, self::pathname($object));
        }
    }

    /** @return array{pattern: string, paths: list<string>, index: int, flags: int} */
    private static function &state(ObjectEntry $object): array
    {
        if (!isset(self::$store[$object->id])) {
            throw new \LogicException('GlobIterator object state missing');
        }

        return self::$store[$object->id];
    }
}

final class GlobIteratorConstruct extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            GlobIteratorBuiltin::CLASS_LC,
            'GlobIterator::__construct()'
        );
        $argCount = \count($frame->calledArgs);
        if ($argCount < 2) {
            throw new \ArgumentCountError(
                'GlobIterator::__construct() expects at least 1 argument, '
                .($argCount - 1).' given'
            );
        }
        $path = VmStreamPath::coerceNonEmptyPathArg(
            $frame->calledArgs[1],
            'GlobIterator::__construct'
        );
        $flags = FilesystemIteratorBuiltin::KEY_AS_PATHNAME;
        if ($argCount >= 3) {
            $flags = SplFilesystemArg::requireIntArg(
                $frame->calledArgs[2],
                'GlobIterator::__construct',
                2,
                'flags'
            );
        }
        GlobIteratorStorage::open($object, $path, $flags);
    }
}

final class GlobIteratorRewind extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('rewind');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            GlobIteratorBuiltin::CLASS_LC,
            'GlobIterator::rewind()'
        );
        GlobIteratorStorage::rewind($object);
    }
}

final class GlobIteratorValid extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('valid');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            GlobIteratorBuiltin::CLASS_LC,
            'GlobIterator::valid()'
        );
        SplIteratorSupport::setReturnBool($frame, GlobIteratorStorage::valid($object));
    }
}

final class GlobIteratorCurrent extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('current');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            GlobIteratorBuiltin::CLASS_LC,
            'GlobIterator::current()'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->object($object);
    }
}

final class GlobIteratorKey extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('key');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            GlobIteratorBuiltin::CLASS_LC,
            'GlobIterator::key()'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int(GlobIteratorStorage::key($object));
    }
}

final class GlobIteratorNext extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('next');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            GlobIteratorBuiltin::CLASS_LC,
            'GlobIterator::next()'
        );
        GlobIteratorStorage::next($object);
    }
}

final class GlobIteratorCount extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('count');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            GlobIteratorBuiltin::CLASS_LC,
            'GlobIterator::count()'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int(GlobIteratorStorage::count($object));
    }
}
