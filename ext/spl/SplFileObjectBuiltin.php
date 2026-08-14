<?php

declare(strict_types=1);

namespace PHPCompiler\ext\spl;

use PHPCompiler\ext\standard\VmCsv;
use PHPCompiler\ext\standard\VmCsvArg;
use PHPCompiler\ext\standard\VmFopenMode;
use PHPCompiler\ext\standard\VmFs;
use PHPCompiler\ext\standard\VmFputcsv;
use PHPCompiler\ext\standard\VmMath;
use PHPCompiler\ext\standard\VmStreamPath;
use PHPCompiler\ext\standard\VmString;
use PHPCompiler\ext\standard\VmVfscanf;
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

    /** php-src ext/spl/spl_directory.c — SPL_FILE_OBJECT_* (#14576). */
    public const READ_AHEAD = 2;

    public const SKIP_EMPTY = 4;

    public const DROP_NEW_LINE = 1;

    public const READ_CSV = 8;

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
        // Zend rematerializes flattened ce->interfaces (php-src ext/spl/spl_directory.c).
        // Traversable before Iterator after RecursiveIterator — not RecursiveIterator parent
        // expansion order. Observable via class_implements() / Reflection (#25799).
        $entry->interfaces = [];
        foreach (['stringable', 'recursiveiterator', 'traversable', 'iterator', 'seekableiterator'] as $ifaceLc) {
            if (isset($ctx->classes[$ifaceLc])) {
                $entry->interfaces[] = $ifaceLc;
            }
        }

        SplClassConstants::registerIntConstants($entry, [
            'READ_AHEAD' => self::READ_AHEAD,
            'SKIP_EMPTY' => self::SKIP_EMPTY,
            'DROP_NEW_LINE' => self::DROP_NEW_LINE,
            'READ_CSV' => self::READ_CSV,
        ]);

        $entry->constructor = new SplFileObjectConstruct();
        $entry->methods['__construct'] = $entry->constructor;
        $entry->methodVisibility['__construct'] = $pub;
        foreach ([
            'fgets' => SplFileObjectFgets::class,
            'fread' => SplFileObjectFread::class,
            'fgetc' => SplFileObjectFgetc::class,
            'fscanf' => SplFileObjectFscanf::class,
            'fwrite' => SplFileObjectFwrite::class,
            'rewind' => SplFileObjectRewind::class,
            'next' => SplFileObjectNext::class,
            'valid' => SplFileObjectValid::class,
            'key' => SplFileObjectKey::class,
            'current' => SplFileObjectCurrent::class,
            'eof' => SplFileObjectEof::class,
            'seek' => SplFileObjectSeek::class,
            'fseek' => SplFileObjectFseek::class,
            'ftell' => SplFileObjectFtell::class,
            'fstat' => SplFileObjectFstat::class,
            'flock' => SplFileObjectFlock::class,
            'fflush' => SplFileObjectFflush::class,
            'ftruncate' => SplFileObjectFtruncate::class,
            'fpassthru' => SplFileObjectFpassthru::class,
            'getcurrentline' => SplFileObjectGetCurrentLine::class,
            'setmaxlinelen' => SplFileObjectSetMaxLineLen::class,
            'getmaxlinelen' => SplFileObjectGetMaxLineLen::class,
            'fgetcsv' => SplFileObjectFgetcsv::class,
            'fputcsv' => SplFileObjectFputcsv::class,
            'setcsvcontrol' => SplFileObjectSetCsvControl::class,
            'getcsvcontrol' => SplFileObjectGetCsvControl::class,
            'setflags' => SplFileObjectSetFlags::class,
            'getflags' => SplFileObjectGetFlags::class,
            'haschildren' => SplFileObjectHasChildren::class,
            'getchildren' => SplFileObjectGetChildren::class,
            '__tostring' => SplFileObjectToString::class,
        ] as $lc => $class) {
            $entry->methods[$lc] = new $class();
            $entry->methodVisibility[$lc] = $pub;
        }
        $entry->methodNames['setcsvcontrol'] = 'setCsvControl';
        $entry->methodNames['getcsvcontrol'] = 'getCsvControl';
        $entry->methodNames['setflags'] = 'setFlags';
        $entry->methodNames['getflags'] = 'getFlags';
        $entry->methodNames['getcurrentline'] = 'getCurrentLine';
        $entry->methodNames['setmaxlinelen'] = 'setMaxLineLen';
        $entry->methodNames['getmaxlinelen'] = 'getMaxLineLen';
        $entry->methodNames['haschildren'] = 'hasChildren';
        $entry->methodNames['getchildren'] = 'getChildren';
        $entry->methodNames['__tostring'] = '__toString';

        $entry->isInternal = true;
        $ctx->classes[self::CLASS_LC] = $entry;
    }

    private static function classIsComplete(ClassEntry $entry): bool
    {
        return isset(
            $entry->methods['fgets'],
            $entry->methods['fread'],
            $entry->methods['fgetc'],
            $entry->methods['fscanf'],
            $entry->methods['fwrite'],
            $entry->methods['rewind'],
            $entry->methods['valid'],
            $entry->methods['current'],
            $entry->methods['eof'],
            $entry->methods['seek'],
            $entry->methods['fseek'],
            $entry->methods['ftell'],
            $entry->methods['fstat'],
            $entry->methods['flock'],
            $entry->methods['fflush'],
            $entry->methods['ftruncate'],
            $entry->methods['fpassthru'],
            $entry->methods['getcurrentline'],
            $entry->methods['setmaxlinelen'],
            $entry->methods['getmaxlinelen'],
            $entry->methods['fgetcsv'],
            $entry->methods['fputcsv'],
            $entry->methods['setflags'],
            $entry->methods['getflags'],
            $entry->methods['haschildren'],
            $entry->methods['getchildren'],
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
                'SplFileObject::__construct('.$pathname.'): Failed to open stream: '
                .self::fopenFailureDetail($pathname, $mode)
            );
        }
        SplFileInfoStorage::init($object, $pathname);
        SplFileObjectStorage::setHandle($object, $handle, $mode);
    }

    /** php-src streams.c — prefer invalid-mode detail / host fopen reason (#6393, #25941). */
    private static function fopenFailureDetail(string $pathname, string $mode): string
    {
        $invalid = VmFopenMode::consumeLastOpenFailureDetail();
        if (null !== $invalid && '' !== $invalid) {
            return $invalid;
        }
        $last = \error_get_last();
        $detail = 'No such file or directory';
        if (\is_array($last) && isset($last['message']) && \is_string($last['message'])) {
            $prefix = 'fopen('.$pathname.'): Failed to open stream: ';
            if (str_starts_with($last['message'], $prefix)) {
                $detail = substr($last['message'], \strlen($prefix));
            } elseif (preg_match('/Failed to open stream: (.+)$/', $last['message'], $m)) {
                $detail = $m[1];
            }
        }
        // VmFsOpenPure appends "b" before host fopen; Zend quotes the user mode (#6393).
        if (!str_contains($mode, 'b')) {
            $detail = str_replace('`'.$mode.'b\'', '`'.$mode.'\'', $detail);
        }

        return $detail;
    }
}

final class SplFileObjectFgets extends VmClassMethod
{
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
        // php-src zim_SplFileObject_fgets — ZEND_PARSE_PARAMETERS_NONE on 8.2 (#30937).
        $this->requireExactUserArgCount($frame, 'SplFileObject::fgets', 0);
        if (null === $frame->returnVar) {
            return;
        }
        $line = SplFileObjectStorage::fgets($object, null);
        if (false === $line) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($line);
    }
}

/** php-src SplFileObject::fread — read up to $length bytes (#19804). */
final class SplFileObjectFread extends VmClassMethod
{
    private const LENGTH_ERROR = 'SplFileObject::fread(): Argument #1 ($length) must be greater than 0';

    public function __construct()
    {
        parent::__construct('fread');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplFileObjectBuiltin::CLASS_LC,
            'SplFileObject::fread()'
        );
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError(
                'SplFileObject::fread() expects exactly 1 argument, '
                .(\count($frame->calledArgs) - 1).' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $length = VmMath::parseIntBuiltinArg(
            $frame->calledArgs[1],
            'SplFileObject::fread',
            1,
            'length'
        );
        if ($length <= 0) {
            throw new \ValueError(self::LENGTH_ERROR);
        }
        $data = VmFs::fread(SplFileObjectStorage::handle($object), $length);
        if (false === $data) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($data);
    }
}

/** php-src SplFileObject::fgetc — read one byte (#19804). */
final class SplFileObjectFgetc extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('fgetc');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplFileObjectBuiltin::CLASS_LC,
            'SplFileObject::fgetc()'
        );
        if (\count($frame->calledArgs) > 1) {
            throw new \ArgumentCountError(
                'SplFileObject::fgetc() expects exactly 0 arguments, '
                .(\count($frame->calledArgs) - 1).' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $byte = VmFs::fgetc(SplFileObjectStorage::handle($object));
        if (false === $byte) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($byte);
    }
}

/**
 * php-src SplFileObject::fscanf — formatted stream input (#19804).
 * Mirrors procedural fscanf() with $this as the stream handle.
 */
final class SplFileObjectFscanf extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('fscanf');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplFileObjectBuiltin::CLASS_LC,
            'SplFileObject::fscanf()'
        );
        $argc = \count($frame->calledArgs);
        if ($argc < 2) {
            throw new \ArgumentCountError(
                'SplFileObject::fscanf() expects at least 1 argument, '
                .($argc - 1).' given'
            );
        }
        $handle = SplFileObjectStorage::handle($object);
        $format = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[1],
            'SplFileObject::fscanf',
            0,
            'format'
        );
        $outVars = [];
        for ($i = 2; $i < $argc; ++$i) {
            $outVars[] = $frame->calledArgs[$i];
        }
        if (null === $frame->returnVar) {
            if ([] !== $outVars) {
                VmVfscanf::parse($handle, $format, $outVars);
            }

            return;
        }
        if ([] === $outVars) {
            $parsed = VmVfscanf::parseToArray($handle, $format);
            if (false === $parsed) {
                $frame->returnVar->bool(false);
            } elseif (null === $parsed) {
                $frame->returnVar->null();
            } else {
                $frame->returnVar->array($parsed);
            }

            return;
        }
        $parsed = VmVfscanf::parse($handle, $format, $outVars);
        if (false === $parsed) {
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->int($parsed);
        }
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
        // READ_CSV — php-src current_zval HashTable (#19663).
        if (\is_array($line)) {
            $frame->returnVar->array(VmFs::csvRowToArray($line));

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
        // php-src zim_SplFileObject_eof — ZEND_PARSE_PARAMETERS_NONE (#30937).
        $this->requireExactUserArgCount($frame, 'SplFileObject::eof', 0);
        if (null === $frame->returnVar) {
            return;
        }
        SplIteratorSupport::setReturnBool($frame, SplFileObjectStorage::eof($object));
    }
}

final class SplFileObjectSeek extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('seek');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplFileObjectBuiltin::CLASS_LC,
            'SplFileObject::seek()'
        );
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(
                'SplFileObject::seek() expects exactly 1 argument, '.($argc - 1).' given'
            );
        }
        $line = VmMath::parseIntBuiltinArg(
            $frame->calledArgs[1],
            'SplFileObject::seek',
            1,
            'line'
        );
        SplFileObjectStorage::seek($object, $line);
    }
}

final class SplFileObjectFseek extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('fseek');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplFileObjectBuiltin::CLASS_LC,
            'SplFileObject::fseek()'
        );
        $argc = \count($frame->calledArgs);
        if ($argc < 2) {
            throw new \ArgumentCountError(
                'SplFileObject::fseek() expects at least 1 argument, '.$argc.' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $offset = VmMath::parseIntBuiltinArg(
            $frame->calledArgs[1],
            'SplFileObject::fseek',
            1,
            'offset'
        );
        $whence = \SEEK_SET;
        if ($argc >= 3) {
            $whence = VmMath::parseIntBuiltinArg(
                $frame->calledArgs[2],
                'SplFileObject::fseek',
                2,
                'whence'
            );
        }
        $frame->returnVar->int(SplFileObjectStorage::fseek($object, $offset, $whence));
    }
}

/** php-src SplFileObject::ftell — php_stream_tell (#19664). */
final class SplFileObjectFtell extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('ftell');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplFileObjectBuiltin::CLASS_LC,
            'SplFileObject::ftell()'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $pos = SplFileObjectStorage::ftell($object);
        if (false === $pos) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->int($pos);
    }
}

/** php-src SplFileObject::fstat (#19664). */
final class SplFileObjectFstat extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('fstat');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplFileObjectBuiltin::CLASS_LC,
            'SplFileObject::fstat()'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $stat = SplFileObjectStorage::fstat($object);
        if (false === $stat) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->array($stat);
    }
}

/** php-src SplFileObject::flock (#19664). */
final class SplFileObjectFlock extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('flock');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplFileObjectBuiltin::CLASS_LC,
            'SplFileObject::flock()'
        );
        $argc = \count($frame->calledArgs);
        if ($argc < 2) {
            throw new \ArgumentCountError(
                'SplFileObject::flock() expects at least 1 argument, '.($argc - 1).' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $operation = VmMath::parseIntBuiltinArg(
            $frame->calledArgs[1],
            'SplFileObject::flock',
            1,
            'operation'
        );
        SplIteratorSupport::setReturnBool($frame, SplFileObjectStorage::flock($object, $operation));
    }
}

/** php-src SplFileObject::fflush (#19664). */
final class SplFileObjectFflush extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('fflush');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplFileObjectBuiltin::CLASS_LC,
            'SplFileObject::fflush()'
        );
        // php-src zim_SplFileObject_fflush — ZEND_PARSE_PARAMETERS_NONE; ACE cites SplFileObject (#30937).
        $this->requireExactUserArgCount($frame, 'SplFileObject::fflush', 0);
        if (null === $frame->returnVar) {
            return;
        }
        SplIteratorSupport::setReturnBool($frame, SplFileObjectStorage::fflush($object));
    }
}

/** php-src SplFileObject::ftruncate (#19664). */
final class SplFileObjectFtruncate extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('ftruncate');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplFileObjectBuiltin::CLASS_LC,
            'SplFileObject::ftruncate()'
        );
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(
                'SplFileObject::ftruncate() expects exactly 1 argument, '.($argc - 1).' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $size = VmMath::parseIntBuiltinArg(
            $frame->calledArgs[1],
            'SplFileObject::ftruncate',
            1,
            'size'
        );
        SplIteratorSupport::setReturnBool($frame, SplFileObjectStorage::ftruncate($object, $size));
    }
}

/** php-src SplFileObject::fpassthru (#19664). */
final class SplFileObjectFpassthru extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('fpassthru');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplFileObjectBuiltin::CLASS_LC,
            'SplFileObject::fpassthru()'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $n = SplFileObjectStorage::fpassthru($object);
        if (false === $n) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->int($n);
    }
}

/** php-src SplFileObject::setMaxLineLen (#19665). */
final class SplFileObjectSetMaxLineLen extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('setMaxLineLen');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplFileObjectBuiltin::CLASS_LC,
            'SplFileObject::setMaxLineLen()'
        );
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(
                'SplFileObject::setMaxLineLen() expects exactly 1 argument, '.($argc - 1).' given'
            );
        }
        $maxLength = VmMath::parseIntBuiltinArg(
            $frame->calledArgs[1],
            'SplFileObject::setMaxLineLen',
            1,
            'maxLength'
        );
        SplFileObjectStorage::setMaxLineLen($object, $maxLength);
    }
}

/** php-src SplFileObject::getMaxLineLen (#19665). */
final class SplFileObjectGetMaxLineLen extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getMaxLineLen');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplFileObjectBuiltin::CLASS_LC,
            'SplFileObject::getMaxLineLen()'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int(SplFileObjectStorage::getMaxLineLen($object));
    }
}

final class SplFileObjectGetCurrentLine extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getCurrentLine');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplFileObjectBuiltin::CLASS_LC,
            'SplFileObject::getCurrentLine()'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $line = SplFileObjectStorage::getCurrentLine($object);
        if (false === $line) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($line);
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
        // php-src SplFileObject::fgetcsv → spl_filesystem_file_read_csv (#24290),
        // not raw stream fgetcsv() which collapses the trailing empty row to false.
        $row = SplFileObjectStorage::fgetcsv($object, $separator, $enclosure, $escape);
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
                    Variable::TYPE_BOOLEAN => 'bool',
                    Variable::TYPE_INTEGER => 'int',
                    Variable::TYPE_FLOAT => 'float',
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
        $written = VmFs::fputcsv($handle, $fields, $separator, $enclosure, $escape, $eol);
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

final class SplFileObjectGetFlags extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getFlags');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplFileObjectBuiltin::CLASS_LC,
            'SplFileObject::getFlags()'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int(SplFileObjectStorage::getFlags($object));
    }
}

final class SplFileObjectSetFlags extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('setFlags');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplFileObjectBuiltin::CLASS_LC,
            'SplFileObject::setFlags()'
        );
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError(
                'SplFileObject::setFlags() expects exactly 1 argument, '
                .(\count($frame->calledArgs) - 1).' given'
            );
        }
        $flags = $frame->calledArgs[1]->resolveIndirect()->toInt();
        SplFileObjectStorage::setFlags($object, $flags);
    }
}

/** php-src spl_directory.c — SplFileObject::hasChildren always false. */
final class SplFileObjectHasChildren extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('hasChildren');
    }

    public function execute(Frame $frame): void
    {
        SplIteratorSupport::receiverIsA(
            $frame,
            SplFileObjectBuiltin::CLASS_LC,
            'SplFileObject::hasChildren()'
        );
        SplIteratorSupport::setReturnBool($frame, false);
    }
}

/** php-src spl_directory.c — SplFileObject::getChildren returns null. */
final class SplFileObjectGetChildren extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getChildren');
    }

    public function execute(Frame $frame): void
    {
        SplIteratorSupport::receiverIsA(
            $frame,
            SplFileObjectBuiltin::CLASS_LC,
            'SplFileObject::getChildren()'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->null();
    }
}

final class SplFileObjectToString extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__toString');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplFileObjectBuiltin::CLASS_LC,
            'SplFileObject::__toString()'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->string(SplFileObjectStorage::readAll($object));
    }
}
