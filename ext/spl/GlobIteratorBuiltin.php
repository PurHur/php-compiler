<?php

declare(strict_types=1);

namespace PHPCompiler\ext\spl;

use PHPCompiler\ext\standard\StdlibConstants;
use PHPCompiler\ext\standard\VmFsGlob;
use PHPCompiler\ext\standard\VmStreamPath;
use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;
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
            'getflags' => GlobIteratorGetFlags::class,
            'setflags' => GlobIteratorSetFlags::class,
        ] as $lc => $class) {
            $entry->methods[$lc] = new $class();
            $entry->methodVisibility[$lc] = $pub;
        }
        $entry->methodNames['count'] = 'count';
        $entry->methodNames['getflags'] = 'getFlags';
        $entry->methodNames['setflags'] = 'setFlags';

        $entry->isInternal = true;
        $ctx->classes[self::CLASS_LC] = $entry;
    }

    private static function classIsComplete(ClassEntry $entry): bool
    {
        return isset(
            $entry->methods['__construct'],
            $entry->methods['rewind'],
            $entry->methods['count'],
            $entry->methods['getflags'],
            $entry->methods['setflags']
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
        // php-src GlobIterator stores FilesystemIterator flags separately from glob()
        // flags — only GLOB_* bits are passed to php_glob (#24254 / re-#22306).
        $globFlags = $flags & StdlibConstants::GLOB_AVAILABLE_FLAGS;
        $result = VmFsGlob::glob($pattern, $globFlags);
        $paths = false === $result ? [] : array_values($result);
        if (0 !== ($flags & FilesystemIteratorBuiltin::SKIP_DOTS)) {
            $paths = array_values(array_filter(
                $paths,
                static fn (string $path): bool => '.' !== basename($path) && '..' !== basename($path)
            ));
        }

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

    public static function key(ObjectEntry $object): int|string
    {
        $state = self::state($object);
        $pathname = self::pathname($object);
        if (0 !== ($state['flags'] & FilesystemIteratorBuiltin::KEY_AS_FILENAME)) {
            return basename($pathname);
        }

        // Default KEY_AS_PATHNAME (0) — php-src GlobIterator keys are path strings (#22306).
        return $pathname;
    }

    public static function count(ObjectEntry $object): int
    {
        return \count(self::state($object)['paths']);
    }

    /** php-src GlobIterator inherits FilesystemIterator::getFlags (#22306). */
    public static function getFlags(ObjectEntry $object): int
    {
        return self::state($object)['flags'];
    }

    /**
     * php-src GlobIterator::setFlags — update flags without re-globbing; rewind cursor
     * like FilesystemIterator::setFlags (#22306).
     */
    public static function setFlags(ObjectEntry $object, int $flags): void
    {
        $state = &self::state($object);
        $state['flags'] = $flags;
        self::rewind($object);
    }

    /**
     * php-src GlobIterator::current — FilesystemIterator current-mode flags (#22306).
     */
    public static function current(Frame $frame, ObjectEntry $object): Variable
    {
        $flags = self::getFlags($object);
        $result = new Variable();
        if (0 !== ($flags & FilesystemIteratorBuiltin::CURRENT_AS_SELF)) {
            $result->object($object);

            return $result;
        }
        if (0 !== ($flags & FilesystemIteratorBuiltin::CURRENT_AS_PATHNAME)) {
            $result->string(self::pathname($object));

            return $result;
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('GlobIterator::current() requires VM context');
        }
        $class = $frame->vmContext->classes[SplFileInfoBuiltin::CLASS_LC] ?? null;
        if (null === $class) {
            throw new \LogicException('SplFileInfo is not registered in this compiler build');
        }
        $info = new ObjectEntry($class);
        $info->constructed = true;
        SplFileInfoStorage::init($info, self::pathname($object));
        $result->object($info);

        return $result;
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
        SplIteratorSupport::copyReturnFrom(
            $frame,
            GlobIteratorStorage::current($frame, $object)
        );
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
        $key = GlobIteratorStorage::key($object);
        if (\is_int($key)) {
            $frame->returnVar->int($key);
        } else {
            $frame->returnVar->string($key);
        }
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
        // php-src zim_GlobIterator_count — ZEND_PARSE_PARAMETERS_NONE (#31010).
        $this->requireExactUserArgCount($frame, 'GlobIterator::count', 0);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int(GlobIteratorStorage::count($object));
    }
}

/** php-src GlobIterator::getFlags via FilesystemIterator (#22306). */
final class GlobIteratorGetFlags extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getFlags');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            GlobIteratorBuiltin::CLASS_LC,
            'GlobIterator::getFlags()'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int(GlobIteratorStorage::getFlags($object));
    }
}

/** php-src GlobIterator::setFlags via FilesystemIterator (#22306). */
final class GlobIteratorSetFlags extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('setFlags');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            GlobIteratorBuiltin::CLASS_LC,
            'GlobIterator::setFlags()'
        );
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError(
                'GlobIterator::setFlags() expects exactly 1 argument, '
                .(\count($frame->calledArgs) - 1).' given'
            );
        }
        $flags = SplFilesystemArg::requireIntArg(
            $frame->calledArgs[1],
            'GlobIterator::setFlags',
            1,
            'flags'
        );
        GlobIteratorStorage::setFlags($object, $flags);
    }
}
