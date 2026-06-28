<?php

declare(strict_types=1);

namespace PHPCompiler\ext\spl;

use PHPCompiler\ext\standard\VmCsv;
use PHPCompiler\ext\standard\VmCsvArg;
use PHPCompiler\ext\standard\VmFs;
use PHPCompiler\ext\standard\VmFputcsv;
use PHPCompiler\ext\standard\VmMath;
use PHPCompiler\ext\standard\VmStreamPath;
use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\Variable;
use PHPCfg\Func as CfgFunc;

/**
 * SplFileObject — file stream wrapper (php-src ext/spl/spl_directory.c; #12520).
 */
final class SplFileObjectBuiltin
{
    public const CLASS_LC = 'splfileobject';

    public static function registerClass(Context $ctx): void
    {
        if (isset($ctx->classes[self::CLASS_LC]) && self::classIsComplete($ctx->classes[self::CLASS_LC])) {
            return;
        }

        SplFileInfoBuiltin::registerClass($ctx);

        $pub = CfgFunc::FLAG_PUBLIC;
        $entry = isset($ctx->classes[self::CLASS_LC])
            ? $ctx->classes[self::CLASS_LC]
            : new ClassEntry('SplFileObject');
        $entry->parentLc = SplFileInfoBuiltin::CLASS_LC;
        if (isset($ctx->classes['stringable'])
            && !\in_array('Stringable', $entry->interfaces, true)) {
            $entry->interfaces[] = 'Stringable';
        }
        foreach (['RecursiveIterator', 'Traversable', 'Iterator', 'SeekableIterator'] as $iface) {
            if (isset($ctx->classes[strtolower($iface)])
                && !\in_array($iface, $entry->interfaces, true)) {
                $entry->interfaces[] = $iface;
            }
        }

        $entry->constructor = new SplFileObjectConstruct();
        $entry->methods['__construct'] = $entry->constructor;
        $entry->methodVisibility['__construct'] = $pub;
        foreach ([
            'fgets' => SplFileObjectFgets::class,
            'fwrite' => SplFileObjectFwrite::class,
            'rewind' => SplFileObjectRewind::class,
            'next' => SplFileObjectNext::class,
            'valid' => SplFileObjectValid::class,
            'key' => SplFileObjectKey::class,
            'current' => SplFileObjectCurrent::class,
            'eof' => SplFileObjectEof::class,
            'fgetcsv' => SplFileObjectFgetcsv::class,
            'fputcsv' => SplFileObjectFputcsv::class,
            'setcsvcontrol' => SplFileObjectSetCsvControl::class,
            'getcsvcontrol' => SplFileObjectGetCsvControl::class,
        ] as $lc => $class) {
            $entry->methods[$lc] = new $class();
            $entry->methodVisibility[$lc] = $pub;
        }
        $entry->methodNames['setcsvcontrol'] = 'setCsvControl';
        $entry->methodNames['getcsvcontrol'] = 'getCsvControl';

        $entry->isInternal = true;
        $ctx->classes[self::CLASS_LC] = $entry;
    }

    private static function classIsComplete(ClassEntry $entry): bool
    {
        return isset(
            $entry->methods['fgets'],
            $entry->methods['fwrite'],
            $entry->methods['rewind'],
            $entry->methods['valid'],
            $entry->methods['current'],
            $entry->methods['eof'],
            $entry->methods['fgetcsv'],
            $entry->methods['fputcsv'],
        );
    }
}

final class SplFileObjectConstruct extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiver(
            $frame,
            SplFileObjectBuiltin::CLASS_LC,
            'SplFileObject::__construct()'
        );
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError(
                'SplFileObject::__construct() expects at least 1 argument, '
                .(\count($frame->calledArgs) - 1).' given'
            );
        }
        $pathname = VmStreamPath::coerceNonEmptyPathArg($frame->calledArgs[1], 'SplFileObject::__construct');
        $mode = 'r';
        if (isset($frame->calledArgs[2])) {
            $mode = VmString::coerceStringBuiltinArg(
                $frame->calledArgs[2],
                'SplFileObject::__construct',
                1,
                'mode'
            );
        }
        if (isset($frame->calledArgs[3])) {
            $useIncludePath = VmMath::parseBoolBuiltinArg(
                $frame->calledArgs[3],
                'SplFileObject::__construct',
                2,
                'use_include_path'
            );
            if ($useIncludePath) {
                $resolved = VmFs::resolveIncludePath($pathname);
                if (false !== $resolved) {
                    $pathname = $resolved;
                }
            }
        }
        $handle = VmFs::fopen($pathname, $mode, $frame->vmContext);
        if (false === $handle) {
            throw new \RuntimeException(
                'SplFileObject::__construct('.$pathname.'): Failed to open stream: No such file or directory'
            );
        }
        SplFileInfoStorage::init($object, $pathname);
        SplFileObjectStorage::setHandle($object, $handle);
    }
}

final class SplFileObjectFgets extends VmClassMethod
{
    private const LENGTH_ERROR = 'SplFileObject::fgets(): Argument #1 ($length) must be greater than 0';

    public function __construct()
    {
        parent::__construct('fgets');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplFileObjectBuiltin::CLASS_LC,
            'SplFileObject::fgets()'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $length = null;
        if (isset($frame->calledArgs[1])) {
            $length = VmMath::parseIntBuiltinArg(
                $frame->calledArgs[1],
                'SplFileObject::fgets',
                1,
                'length'
            );
            if ($length <= 0) {
                throw new \ValueError(self::LENGTH_ERROR);
            }
        }
        $line = SplFileObjectStorage::fgets($object, $length);
        if (false === $line) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($line);
    }
}

final class SplFileObjectRewind extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('rewind');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplFileObjectBuiltin::CLASS_LC,
            'SplFileObject::rewind()'
        );
        SplFileObjectStorage::rewind($object);
    }
}

final class SplFileObjectNext extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('next');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplFileObjectBuiltin::CLASS_LC,
            'SplFileObject::next()'
        );
        SplFileObjectStorage::next($object);
    }
}

final class SplFileObjectValid extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('valid');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplFileObjectBuiltin::CLASS_LC,
            'SplFileObject::valid()'
        );
        if (null === $frame->returnVar) {
            return;
        }
        SplIteratorSupport::setReturnBool($frame, SplFileObjectStorage::valid($object));
    }
}

final class SplFileObjectKey extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('key');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplFileObjectBuiltin::CLASS_LC,
            'SplFileObject::key()'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int(SplFileObjectStorage::key($object));
    }
}

final class SplFileObjectCurrent extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('current');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplFileObjectBuiltin::CLASS_LC,
            'SplFileObject::current()'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $line = SplFileObjectStorage::current($object);
        if (false === $line) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($line);
    }
}

final class SplFileObjectEof extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('eof');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplFileObjectBuiltin::CLASS_LC,
            'SplFileObject::eof()'
        );
        if (null === $frame->returnVar) {
            return;
        }
        SplIteratorSupport::setReturnBool($frame, SplFileObjectStorage::eof($object));
    }
}

final class SplFileObjectFwrite extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('fwrite');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplFileObjectBuiltin::CLASS_LC,
            'SplFileObject::fwrite()'
        );
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError(
                'SplFileObject::fwrite() expects at least 1 argument, '
                .(\count($frame->calledArgs) - 1).' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $data = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[1],
            'SplFileObject::fwrite',
            0,
            'data'
        );
        $length = null;
        if (isset($frame->calledArgs[2])) {
            $length = VmMath::parseIntBuiltinArg(
                $frame->calledArgs[2],
                'SplFileObject::fwrite',
                1,
                'length'
            );
        }
        $written = VmFs::fwrite(SplFileObjectStorage::handle($object), $data, $length);
        if (false === $written) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->int($written);
    }
}

final class SplFileObjectFgetcsv extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('fgetcsv');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplFileObjectBuiltin::CLASS_LC,
            'SplFileObject::fgetcsv()'
        );
        if (null === $frame->returnVar) {
            return;
        }
        [$separator, $enclosure, $escape] = SplFileObjectStorage::getCsvControl($object);
        if (isset($frame->calledArgs[1])) {
            $separator = VmString::coerceStringBuiltinArg(
                $frame->calledArgs[1],
                'SplFileObject::fgetcsv',
                0,
                'separator'
            );
        }
        if (isset($frame->calledArgs[2])) {
            $enclosure = VmString::coerceStringBuiltinArg(
                $frame->calledArgs[2],
                'SplFileObject::fgetcsv',
                1,
                'enclosure'
            );
        }
        if (isset($frame->calledArgs[3])) {
            $escape = VmString::coerceStringBuiltinArg(
                $frame->calledArgs[3],
                'SplFileObject::fgetcsv',
                2,
                'escape'
            );
        }
        VmCsvArg::validateFgetcsvOptions($separator, $enclosure, $escape);
        $row = VmFs::fgetcsv(
            SplFileObjectStorage::handle($object),
            null,
            $separator,
            $enclosure,
            $escape
        );
        if (false === $row) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->array(VmFs::csvRowToArray($row));
    }
}

final class SplFileObjectFputcsv extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('fputcsv');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplFileObjectBuiltin::CLASS_LC,
            'SplFileObject::fputcsv()'
        );
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError(
                'SplFileObject::fputcsv() expects at least 1 argument, '
                .(\count($frame->calledArgs) - 1).' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $fieldsVar = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $fieldsVar->type) {
            throw new \TypeError(
                'SplFileObject::fputcsv(): Argument #1 ($fields) must be of type array, '
                .match ($fieldsVar->type) {
                    Variable::TYPE_NULL => 'null',
                    Variable::TYPE_BOOL => 'bool',
                    Variable::TYPE_INTEGER => 'int',
                    Variable::TYPE_DOUBLE => 'float',
                    Variable::TYPE_STRING => 'string',
                    Variable::TYPE_OBJECT => 'object',
                    default => 'mixed',
                }.' given'
            );
        }
        [$separator, $enclosure, $escape] = SplFileObjectStorage::getCsvControl($object);
        if (isset($frame->calledArgs[2])) {
            $separator = VmString::coerceStringBuiltinArg(
                $frame->calledArgs[2],
                'SplFileObject::fputcsv',
                1,
                'separator'
            );
        }
        if (isset($frame->calledArgs[3])) {
            $enclosure = VmString::coerceStringBuiltinArg(
                $frame->calledArgs[3],
                'SplFileObject::fputcsv',
                2,
                'enclosure'
            );
        }
        if (isset($frame->calledArgs[4])) {
            $escape = VmString::coerceStringBuiltinArg(
                $frame->calledArgs[4],
                'SplFileObject::fputcsv',
                3,
                'escape'
            );
        }
        VmCsvArg::validateFputcsvOptions($separator, $enclosure, $escape);
        $eol = "\n";
        if (isset($frame->calledArgs[5])) {
            $eol = VmString::coerceStringBuiltinArg(
                $frame->calledArgs[5],
                'SplFileObject::fputcsv',
                4,
                'eol'
            );
        }
        $fields = VmFputcsv::coerceFieldList($fieldsVar->toArray()->iterate(true));
        $handle = SplFileObjectStorage::handle($object);
        if ("\n" === $eol) {
            $written = VmFs::fputcsv($handle, $fields, $separator, $enclosure, $escape);
        } else {
            $line = VmCsv::formatLine($fields, $separator, $enclosure, $escape).$eol;
            $written = VmFs::fwrite($handle, $line);
        }
        if (false === $written) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->int($written);
    }
}

final class SplFileObjectSetCsvControl extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('setCsvControl');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplFileObjectBuiltin::CLASS_LC,
            'SplFileObject::setCsvControl()'
        );
        [$separator, $enclosure, $escape] = SplFileObjectStorage::getCsvControl($object);
        if (isset($frame->calledArgs[1])) {
            $separator = VmString::coerceStringBuiltinArg(
                $frame->calledArgs[1],
                'SplFileObject::setCsvControl',
                0,
                'separator'
            );
        }
        if (isset($frame->calledArgs[2])) {
            $enclosure = VmString::coerceStringBuiltinArg(
                $frame->calledArgs[2],
                'SplFileObject::setCsvControl',
                1,
                'enclosure'
            );
        }
        if (isset($frame->calledArgs[3])) {
            $escape = VmString::coerceStringBuiltinArg(
                $frame->calledArgs[3],
                'SplFileObject::setCsvControl',
                2,
                'escape'
            );
        }
        VmCsvArg::validateFputcsvOptions($separator, $enclosure, $escape);
        SplFileObjectStorage::setCsvControl($object, $separator, $enclosure, $escape);
    }
}

final class SplFileObjectGetCsvControl extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getCsvControl');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplFileObjectBuiltin::CLASS_LC,
            'SplFileObject::getCsvControl()'
        );
        if (null === $frame->returnVar) {
            return;
        }
        [$separator, $enclosure, $escape] = SplFileObjectStorage::getCsvControl($object);
        $frame->returnVar->newArray();
        $ht = $frame->returnVar->toArray();
        foreach ([$separator, $enclosure, $escape] as $value) {
            $cell = new Variable();
            $cell->string($value);
            $ht->append($cell);
        }
    }
}
