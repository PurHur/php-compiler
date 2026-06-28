<?php

declare(strict_types=1);

namespace PHPCompiler\ext\spl;

use PHPCompiler\ext\standard\VmStreamPath;
use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;
use PHPCfg\Func as CfgFunc;

/**
 * FilesystemIterator / RecursiveDirectoryIterator — directory iterators with flags
 * (php-src ext/spl/spl_directory.c; #12892).
 */
final class FilesystemIteratorBuiltin
{
    public const CLASS_LC = 'filesystemiterator';

    public const CURRENT_AS_PATHNAME = 0;

    public const CURRENT_AS_FILEINFO = 0;

    public const CURRENT_AS_SELF = 4;

    public const KEY_AS_PATHNAME = 0;

    public const KEY_AS_FILENAME = 2;

    public const NEW_CURRENT_AND_KEY = 256;

    public const SKIP_DOTS = 4096;

    public const UNIX_PATHS = 8192;

    public const FOLLOW_SYMLINKS = 512;

    public static function registerClass(Context $ctx): void
    {
        DirectoryIteratorBuiltin::registerClass($ctx);

        if (isset($ctx->classes[self::CLASS_LC]) && self::classIsComplete($ctx->classes[self::CLASS_LC])) {
            RecursiveDirectoryIteratorBuiltin::registerClass($ctx);

            return;
        }

        $pub = CfgFunc::FLAG_PUBLIC;
        $entry = isset($ctx->classes[self::CLASS_LC])
            ? $ctx->classes[self::CLASS_LC]
            : new ClassEntry('FilesystemIterator');
        $entry->parentLc = DirectoryIteratorBuiltin::CLASS_LC;
        foreach (['Iterator', 'Traversable', 'SeekableIterator', 'Stringable'] as $iface) {
            if (isset($ctx->classes[strtolower($iface)])
                && !\in_array($iface, $entry->interfaces, true)) {
                $entry->interfaces[] = $iface;
            }
        }

        SplClassConstants::registerIntConstants($entry, [
            'CURRENT_AS_PATHNAME' => self::CURRENT_AS_PATHNAME,
            'CURRENT_AS_FILEINFO' => self::CURRENT_AS_FILEINFO,
            'CURRENT_AS_SELF' => self::CURRENT_AS_SELF,
            'KEY_AS_PATHNAME' => self::KEY_AS_PATHNAME,
            'KEY_AS_FILENAME' => self::KEY_AS_FILENAME,
            'NEW_CURRENT_AND_KEY' => self::NEW_CURRENT_AND_KEY,
            'SKIP_DOTS' => self::SKIP_DOTS,
            'UNIX_PATHS' => self::UNIX_PATHS,
            'FOLLOW_SYMLINKS' => self::FOLLOW_SYMLINKS,
        ]);

        $entry->constructor = new FilesystemIteratorConstruct();
        $entry->methods['__construct'] = $entry->constructor;
        $entry->methodVisibility['__construct'] = $pub;
        $entry->methods['getflags'] = new FilesystemIteratorGetFlags();
        $entry->methodVisibility['getflags'] = $pub;
        $entry->methodNames['getflags'] = 'getFlags';
        $entry->methods['setflags'] = new FilesystemIteratorSetFlags();
        $entry->methodVisibility['setflags'] = $pub;
        $entry->methodNames['setflags'] = 'setFlags';

        $entry->isInternal = true;
        $ctx->classes[self::CLASS_LC] = $entry;

        RecursiveDirectoryIteratorBuiltin::registerClass($ctx);
    }

    private static function classIsComplete(ClassEntry $entry): bool
    {
        return isset($entry->methods['__construct'], $entry->methods['getflags']);
    }
}

final class RecursiveDirectoryIteratorBuiltin
{
    public const CLASS_LC = 'recursivedirectoryiterator';

    public static function registerClass(Context $ctx): void
    {
        if (isset($ctx->classes[self::CLASS_LC]) && self::classIsComplete($ctx->classes[self::CLASS_LC])) {
            return;
        }

        $pub = CfgFunc::FLAG_PUBLIC;
        $entry = isset($ctx->classes[self::CLASS_LC])
            ? $ctx->classes[self::CLASS_LC]
            : new ClassEntry('RecursiveDirectoryIterator');
        $entry->parentLc = FilesystemIteratorBuiltin::CLASS_LC;
        foreach (['Stringable', 'SeekableIterator', 'Traversable', 'Iterator', 'RecursiveIterator'] as $iface) {
            if (isset($ctx->classes[strtolower($iface)])
                && !\in_array($iface, $entry->interfaces, true)) {
                $entry->interfaces[] = $iface;
            }
        }

        $entry->constructor = new RecursiveDirectoryIteratorConstruct();
        $entry->methods['__construct'] = $entry->constructor;
        $entry->methodVisibility['__construct'] = $pub;
        $entry->methods['haschildren'] = new RecursiveDirectoryIteratorHasChildren();
        $entry->methodVisibility['haschildren'] = $pub;
        $entry->methodNames['haschildren'] = 'hasChildren';
        $entry->methods['getchildren'] = new RecursiveDirectoryIteratorGetChildren();
        $entry->methodVisibility['getchildren'] = $pub;
        $entry->methodNames['getchildren'] = 'getChildren';

        $entry->isInternal = true;
        $ctx->classes[self::CLASS_LC] = $entry;
    }

    private static function classIsComplete(ClassEntry $entry): bool
    {
        return isset($entry->methods['__construct'], $entry->methods['haschildren']);
    }
}

final class FilesystemIteratorConstruct extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            FilesystemIteratorBuiltin::CLASS_LC,
            'FilesystemIterator::__construct()'
        );
        $argCount = \count($frame->calledArgs);
        if ($argCount < 2) {
            throw new \ArgumentCountError(
                'FilesystemIterator::__construct() expects at least 1 argument, '
                .($argCount - 1).' given'
            );
        }
        $path = VmStreamPath::coerceNonEmptyPathArg(
            $frame->calledArgs[1],
            'FilesystemIterator::__construct'
        );
        $flags = FilesystemIteratorBuiltin::SKIP_DOTS;
        if ($argCount >= 3) {
            $flags = SplFilesystemArg::requireIntArg(
                $frame->calledArgs[2],
                'FilesystemIterator::__construct',
                2,
                'flags'
            );
        }
        DirectoryIteratorStorage::open($object, $path, $flags);
    }
}

final class RecursiveDirectoryIteratorConstruct extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            RecursiveDirectoryIteratorBuiltin::CLASS_LC,
            'RecursiveDirectoryIterator::__construct()'
        );
        $argCount = \count($frame->calledArgs);
        if ($argCount < 2) {
            throw new \ArgumentCountError(
                'RecursiveDirectoryIterator::__construct() expects at least 1 argument, '
                .($argCount - 1).' given'
            );
        }
        $path = VmStreamPath::coerceNonEmptyPathArg(
            $frame->calledArgs[1],
            'RecursiveDirectoryIterator::__construct'
        );
        $flags = FilesystemIteratorBuiltin::CURRENT_AS_FILEINFO
            | FilesystemIteratorBuiltin::KEY_AS_FILENAME
            | FilesystemIteratorBuiltin::SKIP_DOTS;
        if ($argCount >= 3) {
            $flags = SplFilesystemArg::requireIntArg(
                $frame->calledArgs[2],
                'RecursiveDirectoryIterator::__construct',
                2,
                'flags'
            );
        }
        DirectoryIteratorStorage::open($object, $path, $flags);
    }
}

/** @internal */
final class SplFilesystemArg
{
    public static function requireIntArg(Variable $var, string $function, int $argIndex, string $paramName): int
    {
        $resolved = $var->resolveIndirect();
        if (Variable::TYPE_INTEGER !== $resolved->type) {
            throw new \TypeError(
                $function.'(): Argument #'.$argIndex.' ($'.$paramName.') must be of type int, '
                .self::typeLabel($resolved).' given'
            );
        }

        return $resolved->toInt();
    }

    public static function typeLabel(Variable $var): string
    {
        return match ($var->type) {
            Variable::TYPE_NULL => 'null',
            Variable::TYPE_BOOL => 'bool',
            Variable::TYPE_INTEGER => 'int',
            Variable::TYPE_DOUBLE => 'float',
            Variable::TYPE_STRING => 'string',
            Variable::TYPE_ARRAY => 'array',
            Variable::TYPE_OBJECT => 'object',
            default => 'mixed',
        };
    }
}

final class RecursiveDirectoryIteratorHasChildren extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('hasChildren');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            RecursiveDirectoryIteratorBuiltin::CLASS_LC,
            'RecursiveDirectoryIterator::hasChildren()'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $allowLinks = false;
        if (\count($frame->calledArgs) >= 2) {
            $allowLinks = self::requireBoolArg(
                $frame->calledArgs[1],
                'RecursiveDirectoryIterator::hasChildren',
                1,
                'allowLinks'
            );
        }
        $frame->returnVar->bool(self::objectHasChildren($object, $allowLinks));
    }

    private static function objectHasChildren(ObjectEntry $object, bool $allowLinks): bool
    {
        if (!DirectoryIteratorStorage::valid($object)) {
            return false;
        }
        $path = DirectoryIteratorStorage::pathname($object);
        if (!is_dir($path)) {
            return false;
        }
        if (!$allowLinks && is_link($path)) {
            return false;
        }

        return true;
    }

    private static function requireBoolArg(Variable $var, string $function, int $argIndex, string $paramName): bool
    {
        $resolved = $var->resolveIndirect();
        if (Variable::TYPE_BOOL !== $resolved->type) {
            throw new \TypeError(
                $function.'(): Argument #'.$argIndex.' ($'.$paramName.') must be of type bool, '
                .SplFilesystemArg::typeLabel($resolved).' given'
            );
        }

        return $resolved->toBool();
    }
}

final class RecursiveDirectoryIteratorGetChildren extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getChildren');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            RecursiveDirectoryIteratorBuiltin::CLASS_LC,
            'RecursiveDirectoryIterator::getChildren()'
        );
        if (null === $frame->returnVar || null === $frame->vmContext) {
            return;
        }
        if (!DirectoryIteratorStorage::valid($object)) {
            throw new \LogicException('RecursiveDirectoryIterator has no current entry');
        }
        $path = DirectoryIteratorStorage::pathname($object);
        $flags = DirectoryIteratorStorage::iteratorState($object)['flags'];
        $class = $frame->vmContext->classes[RecursiveDirectoryIteratorBuiltin::CLASS_LC];
        $child = new ObjectEntry($class);
        $child->constructed = true;
        DirectoryIteratorStorage::open($child, $path, $flags);
        $frame->returnVar->object($child);
    }
}

final class FilesystemIteratorGetFlags extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getFlags');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            FilesystemIteratorBuiltin::CLASS_LC,
            'FilesystemIterator::getFlags()'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int(DirectoryIteratorStorage::getFlags($object));
    }
}

final class FilesystemIteratorSetFlags extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('setFlags');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            FilesystemIteratorBuiltin::CLASS_LC,
            'FilesystemIterator::setFlags()'
        );
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError(
                'FilesystemIterator::setFlags() expects exactly 1 argument, '
                .(\count($frame->calledArgs) - 1).' given'
            );
        }
        $flags = SplFilesystemArg::requireIntArg(
            $frame->calledArgs[1],
            'FilesystemIterator::setFlags',
            1,
            'flags'
        );
        DirectoryIteratorStorage::setFlags($object, $flags);
    }
}
