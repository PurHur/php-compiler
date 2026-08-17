<?php

declare(strict_types=1);

namespace PHPCompiler\ext\spl;

use PHPCompiler\ext\standard\VmMath;
use PHPCompiler\ext\standard\VmStreamPath;
use PHPCompiler\ext\standard\VmString;
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

    public const CURRENT_AS_PATHNAME = 32;

    public const CURRENT_AS_FILEINFO = 0;

    public const CURRENT_AS_SELF = 16;

    /** SPL_FILE_DIR_CURRENT_MODE_MASK — php-src ext/spl/spl_directory.h (#20070). */
    public const CURRENT_MODE_MASK = 0x000000F0;

    public const KEY_AS_PATHNAME = 0;

    public const KEY_AS_FILENAME = 256;

    public const NEW_CURRENT_AND_KEY = 256;

    /** SPL_FILE_DIR_KEY_MODE_MASK — php-src ext/spl/spl_directory.h (#20070). */
    public const KEY_MODE_MASK = 0x00000F00;

    public const SKIP_DOTS = 4096;

    public const UNIX_PATHS = 8192;

    /** SPL_FILE_DIR_FOLLOW_SYMLINKS — php-src ext/spl/spl_directory.h (#20070). */
    public const FOLLOW_SYMLINKS = 0x00004000;

    /** SPL_FILE_DIR_OTHERS_MASK — php-src ext/spl/spl_directory.h (#20070). */
    public const OTHER_MODE_MASK = 0x00007000;

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
            'CURRENT_MODE_MASK' => self::CURRENT_MODE_MASK,
            'KEY_AS_PATHNAME' => self::KEY_AS_PATHNAME,
            'KEY_AS_FILENAME' => self::KEY_AS_FILENAME,
            'KEY_MODE_MASK' => self::KEY_MODE_MASK,
            'NEW_CURRENT_AND_KEY' => self::NEW_CURRENT_AND_KEY,
            'SKIP_DOTS' => self::SKIP_DOTS,
            'UNIX_PATHS' => self::UNIX_PATHS,
            'FOLLOW_SYMLINKS' => self::FOLLOW_SYMLINKS,
            'OTHER_MODE_MASK' => self::OTHER_MODE_MASK,
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
        $entry->methods['key'] = new FilesystemIteratorKey();
        $entry->methodVisibility['key'] = $pub;
        $entry->methods['current'] = new FilesystemIteratorCurrent();
        $entry->methodVisibility['current'] = $pub;

        $entry->isInternal = true;
        $ctx->classes[self::CLASS_LC] = $entry;

        RecursiveDirectoryIteratorBuiltin::registerClass($ctx);
    }

    private static function classIsComplete(ClassEntry $entry): bool
    {
        return isset(
            $entry->methods['__construct'],
            $entry->methods['getflags'],
            $entry->methods['key'],
            $entry->methods['current']
        );
    }
}

final class FilesystemIteratorKey extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('key');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            FilesystemIteratorBuiltin::CLASS_LC,
            'FilesystemIterator::key()'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->string(DirectoryIteratorStorage::filesystemKey($object));
    }
}

final class FilesystemIteratorCurrent extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('current');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            FilesystemIteratorBuiltin::CLASS_LC,
            'FilesystemIterator::current()'
        );
        SplIteratorSupport::copyReturnFrom(
            $frame,
            DirectoryIteratorStorage::filesystemCurrent($frame, $object)
        );
    }
}

final class RecursiveDirectoryIteratorBuiltin
{
    public const CLASS_LC = 'recursivedirectoryiterator';

    public static function registerClass(Context $ctx): void
    {
        if (isset($ctx->classes[self::CLASS_LC])) {
            $existing = $ctx->classes[self::CLASS_LC];
            if (!\in_array('RecursiveIterator', $existing->interfaces, true)) {
                $existing->interfaces[] = 'RecursiveIterator';
            }
            if (self::classIsComplete($existing)) {
                return;
            }
        }

        $pub = CfgFunc::FLAG_PUBLIC;
        $entry = isset($ctx->classes[self::CLASS_LC])
            ? $ctx->classes[self::CLASS_LC]
            : new ClassEntry('RecursiveDirectoryIterator');
        $entry->parentLc = FilesystemIteratorBuiltin::CLASS_LC;
        foreach (['Stringable', 'SeekableIterator', 'Traversable', 'Iterator'] as $iface) {
            if (isset($ctx->classes[strtolower($iface)])
                && !\in_array($iface, $entry->interfaces, true)) {
                $entry->interfaces[] = $iface;
            }
        }
        if (!\in_array('RecursiveIterator', $entry->interfaces, true)) {
            $entry->interfaces[] = 'RecursiveIterator';
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
        $entry->methods['getsubpath'] = new RecursiveDirectoryIteratorGetSubPath();
        $entry->methodVisibility['getsubpath'] = $pub;
        $entry->methodNames['getsubpath'] = 'getSubPath';
        $entry->methods['getsubpathname'] = new RecursiveDirectoryIteratorGetSubPathname();
        $entry->methodVisibility['getsubpathname'] = $pub;
        $entry->methodNames['getsubpathname'] = 'getSubPathname';

        $entry->isInternal = true;
        $ctx->classes[self::CLASS_LC] = $entry;
    }

    private static function classIsComplete(ClassEntry $entry): bool
    {
        return isset(
            $entry->methods['__construct'],
            $entry->methods['haschildren'],
            $entry->methods['getsubpath'],
            $entry->methods['getsubpathname']
        );
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
        $this->requireUserArgCountRange($frame, 'FilesystemIterator::__construct', 1, 2);
        $argCount = \count($frame->calledArgs);
        // php-src spl_directory.stub.php — string $directory; empty → zend_argument_value_error (#31512).
        $path = VmStreamPath::coerceNonEmptyPathArg(
            $frame->calledArgs[1],
            'FilesystemIterator::__construct',
            0,
            'directory',
            VmString::emptyStringArgValueErrorMessageCannot('FilesystemIterator::__construct', 0, 'directory')
        );
        // php-src zim_FilesystemIterator___construct — Z_PARAM_LONG $flags; omitted → SKIP_DOTS;
        // explicit null → E_DEPRECATED then 0 outside strict_types (#31721).
        $flags = FilesystemIteratorBuiltin::SKIP_DOTS;
        if ($argCount >= 3) {
            $flags = VmMath::parseZParamLongBuiltinArgForFrame(
                $frame,
                2,
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
        $this->requireUserArgCountRange($frame, 'RecursiveDirectoryIterator::__construct', 1, 2);
        $argCount = \count($frame->calledArgs);
        // php-src spl_directory.stub.php — string $directory; empty → zend_argument_value_error (#31512).
        $path = VmStreamPath::coerceNonEmptyPathArg(
            $frame->calledArgs[1],
            'RecursiveDirectoryIterator::__construct',
            0,
            'directory',
            VmString::emptyStringArgValueErrorMessageCannot('RecursiveDirectoryIterator::__construct', 0, 'directory')
        );
        // php-src RecursiveDirectoryIterator defaults to flags=0 (include dots;
        // CURRENT_AS_FILEINFO / KEY_AS_PATHNAME). Do NOT inherit FilesystemIterator's
        // SKIP_DOTS default (#20145). Z_PARAM_LONG soft-null DEP+0 (#31721).
        $flags = 0;
        if ($argCount >= 3) {
            $flags = VmMath::parseZParamLongBuiltinArgForFrame(
                $frame,
                2,
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
            Variable::TYPE_BOOLEAN => 'bool',
            Variable::TYPE_INTEGER => 'int',
            Variable::TYPE_FLOAT => 'float',
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
        // php-src spl_filesystem_dir_has_children — never recurse into . / .. (#24291).
        if (DirectoryIteratorStorage::isDot($object)) {
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
        if (Variable::TYPE_BOOLEAN !== $resolved->type) {
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
        $subPath = DirectoryIteratorStorage::childSubPath($object);
        $class = $frame->vmContext->classes[RecursiveDirectoryIteratorBuiltin::CLASS_LC];
        $child = new ObjectEntry($class);
        $child->constructed = true;
        DirectoryIteratorStorage::open($child, $path, $flags, $subPath);
        $frame->returnVar->object($child);
    }
}

final class RecursiveDirectoryIteratorGetSubPath extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getSubPath');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            RecursiveDirectoryIteratorBuiltin::CLASS_LC,
            'RecursiveDirectoryIterator::getSubPath()'
        );
        // php-src zim_RecursiveDirectoryIterator_getSubPath — ZEND_PARSE_PARAMETERS_NONE (#30936).
        $this->requireExactUserArgCount($frame, 'RecursiveDirectoryIterator::getSubPath', 0);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->string(DirectoryIteratorStorage::subPath($object));
    }
}

final class RecursiveDirectoryIteratorGetSubPathname extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getSubPathname');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            RecursiveDirectoryIteratorBuiltin::CLASS_LC,
            'RecursiveDirectoryIterator::getSubPathname()'
        );
        // php-src zim_RecursiveDirectoryIterator_getSubPathname — ZEND_PARSE_PARAMETERS_NONE (#30936).
        $this->requireExactUserArgCount($frame, 'RecursiveDirectoryIterator::getSubPathname', 0);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->string(DirectoryIteratorStorage::subPathname($object));
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
        // php-src zim_FilesystemIterator_getFlags — ZEND_PARSE_PARAMETERS_NONE (#30937).
        $this->requireExactUserArgCount($frame, 'FilesystemIterator::getFlags', 0);
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
        // php-src zim_FilesystemIterator_setFlags — exactly 1 user arg (#31009).
        $this->requireExactUserArgCount($frame, 'FilesystemIterator::setFlags', 1);
        $flags = SplFilesystemArg::requireIntArg(
            $frame->calledArgs[1],
            'FilesystemIterator::setFlags',
            1,
            'flags'
        );
        DirectoryIteratorStorage::setFlags($object, $flags);
    }
}
