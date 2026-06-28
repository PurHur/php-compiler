<?php

declare(strict_types=1);

namespace PHPCompiler\ext\spl;

use PHPCompiler\ext\standard\VmDir;
use PHPCompiler\ext\standard\VmStreamPath;
use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;
use PHPCfg\Func as CfgFunc;

/**
 * DirectoryIterator — directory listing iterator (php-src ext/spl/spl_directory.c; #12629).
 */
final class DirectoryIteratorBuiltin
{
    public const CLASS_LC = 'directoryiterator';

    public static function registerClass(Context $ctx): void
    {
        if (isset($ctx->classes[self::CLASS_LC]) && self::classIsComplete($ctx->classes[self::CLASS_LC])) {
            return;
        }

        SplFileInfoBuiltin::registerClass($ctx);

        $pub = CfgFunc::FLAG_PUBLIC;
        $entry = isset($ctx->classes[self::CLASS_LC])
            ? $ctx->classes[self::CLASS_LC]
            : new ClassEntry('DirectoryIterator');
        $entry->parentLc = SplFileInfoBuiltin::CLASS_LC;
        foreach (['Stringable', 'SeekableIterator', 'Traversable', 'Iterator'] as $iface) {
            if (isset($ctx->classes[strtolower($iface)])
                && !\in_array($iface, $entry->interfaces, true)) {
                $entry->interfaces[] = $iface;
            }
        }

        $entry->constructor = new DirectoryIteratorConstruct();
        $entry->methods['__construct'] = $entry->constructor;
        $entry->methodVisibility['__construct'] = $pub;
        foreach ([
            'rewind' => DirectoryIteratorRewind::class,
            'valid' => DirectoryIteratorValid::class,
            'current' => DirectoryIteratorCurrent::class,
            'key' => DirectoryIteratorKey::class,
            'next' => DirectoryIteratorNext::class,
        ] as $lc => $class) {
            $entry->methods[$lc] = new $class();
            $entry->methodVisibility[$lc] = $pub;
        }

        $entry->isInternal = true;
        $ctx->classes[self::CLASS_LC] = $entry;
    }

    private static function classIsComplete(ClassEntry $entry): bool
    {
        return isset($entry->methods['valid'], $entry->methods['rewind'], $entry->methods['current']);
    }
}

/** @internal */
final class DirectoryIteratorStorage
{
    /** @var array<int, array{dirPath: string, handle: int, filename: string|false, index: int, flags: int}> */
    private static array $store = [];

    public const FLAG_SKIP_DOTS = 4096;

    public static function open(ObjectEntry $object, string $path, int $flags = 0): void
    {
        $handle = VmDir::opendir($path);
        if (false === $handle) {
            throw new \RuntimeException(
                'DirectoryIterator::__construct('.$path.'): Failed to open directory: No such file or directory'
            );
        }
        self::$store[$object->id] = [
            'dirPath' => $path,
            'handle' => $handle,
            'filename' => false,
            'index' => 0,
            'flags' => $flags,
        ];
        self::rewind($object);
    }

    public static function rewind(ObjectEntry $object): void
    {
        $state = &self::$store[$object->id];
        VmDir::rewinddir($state['handle']);
        $state['index'] = 0;
        self::readCurrent($object);
    }

    public static function next(ObjectEntry $object): void
    {
        $state = &self::$store[$object->id];
        ++$state['index'];
        self::readCurrent($object);
    }

    public static function valid(ObjectEntry $object): bool
    {
        return false !== self::state($object)['filename'];
    }

    public static function key(ObjectEntry $object): int
    {
        return self::state($object)['index'];
    }

    public static function pathname(ObjectEntry $object): string
    {
        $state = self::state($object);
        if (false === $state['filename']) {
            return $state['dirPath'];
        }

        return self::joinPath($state['dirPath'], $state['filename']);
    }

    /** @return array{dirPath: string, handle: int, filename: string|false, index: int, flags: int} */
    public static function iteratorState(ObjectEntry $object): array
    {
        return self::state($object);
    }

    public static function getFlags(ObjectEntry $object): int
    {
        return self::state($object)['flags'];
    }

    public static function setFlags(ObjectEntry $object, int $flags): void
    {
        if (!isset(self::$store[$object->id])) {
            throw new \LogicException('DirectoryIterator object state missing');
        }
        self::$store[$object->id]['flags'] = $flags;
        self::rewind($object);
    }

    /** @return array{dirPath: string, handle: int, filename: string|false, index: int, flags: int} */
    private static function state(ObjectEntry $object): array
    {
        if (!isset(self::$store[$object->id])) {
            throw new \LogicException('DirectoryIterator object state missing');
        }

        return self::$store[$object->id];
    }

    private static function readCurrent(ObjectEntry $object): void
    {
        $state = &self::$store[$object->id];
        do {
            $entry = VmDir::readdir($state['handle']);
            $state['filename'] = false === $entry ? false : $entry;
        } while (
            false !== $state['filename']
            && self::shouldSkipDots($state)
            && self::isDotEntry($state['filename'])
        );
        SplFileInfoStorage::init($object, self::pathname($object));
    }

    private static function shouldSkipDots(array $state): bool
    {
        return 0 !== ($state['flags'] & self::FLAG_SKIP_DOTS);
    }

    private static function isDotEntry(string $name): bool
    {
        return '.' === $name || '..' === $name;
    }

    private static function joinPath(string $dir, string $name): string
    {
        if ('' === $dir || '.' === $dir) {
            return $name;
        }
        if (str_ends_with($dir, '/') || str_ends_with($dir, \DIRECTORY_SEPARATOR)) {
            return $dir.$name;
        }

        return $dir.\DIRECTORY_SEPARATOR.$name;
    }
}

final class DirectoryIteratorConstruct extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            DirectoryIteratorBuiltin::CLASS_LC,
            'DirectoryIterator::__construct()'
        );
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError(
                'DirectoryIterator::__construct() expects exactly 1 argument, '
                .(\count($frame->calledArgs) - 1).' given'
            );
        }
        $path = VmStreamPath::coerceNonEmptyPathArg(
            $frame->calledArgs[1],
            'DirectoryIterator::__construct'
        );
        DirectoryIteratorStorage::open($object, $path);
    }
}

final class DirectoryIteratorRewind extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('rewind');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            DirectoryIteratorBuiltin::CLASS_LC,
            'DirectoryIterator::rewind()'
        );
        DirectoryIteratorStorage::rewind($object);
    }
}

final class DirectoryIteratorValid extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('valid');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            DirectoryIteratorBuiltin::CLASS_LC,
            'DirectoryIterator::valid()'
        );
        SplIteratorSupport::setReturnBool($frame, DirectoryIteratorStorage::valid($object));
    }
}

final class DirectoryIteratorCurrent extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('current');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            DirectoryIteratorBuiltin::CLASS_LC,
            'DirectoryIterator::current()'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->object($object);
    }
}

final class DirectoryIteratorKey extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('key');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            DirectoryIteratorBuiltin::CLASS_LC,
            'DirectoryIterator::key()'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int(DirectoryIteratorStorage::key($object));
    }
}

final class DirectoryIteratorNext extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('next');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            DirectoryIteratorBuiltin::CLASS_LC,
            'DirectoryIterator::next()'
        );
        DirectoryIteratorStorage::next($object);
    }
}
