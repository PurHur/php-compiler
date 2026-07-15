<?php

declare(strict_types=1);

namespace PHPCompiler\ext\fileinfo;

use PHPCompiler\Frame;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;
use PHPCompiler\ext\standard\VmFs;
use PHPCompiler\ext\standard\VmMime;
use PHPCompiler\ext\standard\VmStreamOpenFailure;

/**
 * finfo object state + MIME sniff (php-src ext/fileinfo/fileinfo.c; #3366).
 *
 * PHP-in-PHP: reuses {@see VmMime::detectFromBytes()} — no libmagic C runtime.
 */
final class VmFinfo
{
    public const CLASS_LC = 'finfo';

    /** @var array<int, array{flags: int, closed: bool}> */
    private static array $state = [];

    public static function registerClass(Context $ctx): void
    {
        BuiltinClasses::register($ctx);
    }

    public static function open(int $flags, Context $ctx): Variable
    {
        self::registerClass($ctx);
        $var = new Variable(Variable::TYPE_OBJECT);
        $object = new ObjectEntry($ctx->classes[self::CLASS_LC]);
        $object->constructed = true;
        self::bind($object, $flags);
        $var->object($object);

        return $var;
    }

    /** Attach flags to an already-allocated finfo receiver (ctor path). */
    public static function bind(ObjectEntry $finfo, int $flags): void
    {
        self::$state[$finfo->id] = ['flags' => $flags, 'closed' => false];
    }

    public static function close(ObjectEntry $finfo): bool
    {
        if (!isset(self::$state[$finfo->id])) {
            return true;
        }
        self::$state[$finfo->id]['closed'] = true;
        unset(self::$state[$finfo->id]);

        return true;
    }

    public static function setFlags(ObjectEntry $finfo, int $flags): bool
    {
        self::ensureLive($finfo, 'finfo_set_flags');
        self::$state[$finfo->id]['flags'] = $flags;

        return true;
    }

    public static function flagsOf(ObjectEntry $finfo): int
    {
        return self::$state[$finfo->id]['flags'] ?? FileinfoConstants::FILEINFO_NONE;
    }

    public static function isFinfoObject(?ObjectEntry $object): bool
    {
        return null !== $object && self::CLASS_LC === \strtolower($object->class->name);
    }

    public static function requireFinfoArg(Variable $operand, string $function, int $argIndex = 0): ObjectEntry
    {
        $operand = $operand->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $operand->type) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($finfo) must be of type finfo, %s given',
                $function,
                $argIndex + 1,
                self::typeName($operand)
            ));
        }
        $object = $operand->toObject();
        if (!self::isFinfoObject($object) || !isset(self::$state[$object->id])) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($finfo) must be of type finfo, %s given',
                $function,
                $argIndex + 1,
                self::typeName($operand)
            ));
        }

        return $object;
    }

    /**
     * @return string|false
     */
    public static function file(ObjectEntry $finfo, string $path, int $flags, Frame $frame, string $function)
    {
        self::ensureLive($finfo, $function);
        $effective = 0 !== $flags ? $flags : self::flagsOf($finfo);
        $data = VmFs::fileGetContents($path);
        if (false === $data) {
            VmStreamOpenFailure::warnFailedToOpen($frame, $function, $path);

            return false;
        }

        return self::format($data, $effective);
    }

    /**
     * @return string|false
     */
    public static function buffer(ObjectEntry $finfo, string $string, int $flags, string $function)
    {
        self::ensureLive($finfo, $function);
        $effective = 0 !== $flags ? $flags : self::flagsOf($finfo);

        return self::format($string, $effective);
    }

    public static function coerceFlagsArg(?Variable $operand): int
    {
        if (null === $operand) {
            return FileinfoConstants::FILEINFO_NONE;
        }
        $operand = $operand->resolveIndirect();
        if (Variable::TYPE_NULL === $operand->type) {
            return FileinfoConstants::FILEINFO_NONE;
        }

        return $operand->toInt();
    }

    private static function format(string $data, int $flags): string
    {
        $mime = VmMime::detectFromBytes($data);
        $encoding = self::guessEncoding($data, $mime);

        $wantType = 0 !== ($flags & FileinfoConstants::FILEINFO_MIME_TYPE)
            || FileinfoConstants::FILEINFO_MIME === ($flags & FileinfoConstants::FILEINFO_MIME);
        $wantEncoding = 0 !== ($flags & FileinfoConstants::FILEINFO_MIME_ENCODING);

        if ($wantType && $wantEncoding) {
            return $mime.'; charset='.$encoding;
        }
        if ($wantType) {
            return $mime;
        }
        if ($wantEncoding) {
            return $encoding;
        }

        // FILEINFO_NONE / FILEINFO_RAW — approximate libmagic human descriptions.
        return self::humanDescription($mime, $data);
    }

    private static function guessEncoding(string $data, string $mime): string
    {
        if (0 === \strpos($mime, 'text/') || 'application/x-empty' === $mime) {
            for ($i = 0, $len = \min(\strlen($data), 4096); $i < $len; ++$i) {
                if (\ord($data[$i]) > 127) {
                    return 'utf-8';
                }
            }

            return 'us-ascii';
        }

        return 'binary';
    }

    private static function humanDescription(string $mime, string $data): string
    {
        switch ($mime) {
            case 'text/x-php':
                return false === \strpos($data, "\n") && false === \strpos($data, "\r")
                    ? 'PHP script, ASCII text, with no line terminators'
                    : 'PHP script, ASCII text';
            case 'text/plain':
                return 'ASCII text';
            case 'image/jpeg':
                return 'JPEG image data';
            case 'image/png':
                return 'PNG image data';
            case 'image/gif':
                return 'GIF image data';
            case 'application/pdf':
                return 'PDF document';
            case 'application/x-empty':
                return 'empty';
            default:
                return 'data';
        }
    }

    private static function ensureLive(ObjectEntry $finfo, string $function): void
    {
        if (!self::isFinfoObject($finfo) || !isset(self::$state[$finfo->id])) {
            throw new \TypeError($function.'(): Argument #1 ($finfo) must be of type finfo');
        }
    }

    private static function typeName(Variable $operand): string
    {
        switch ($operand->type) {
            case Variable::TYPE_NULL:
                return 'null';
            case Variable::TYPE_INTEGER:
                return 'int';
            case Variable::TYPE_FLOAT:
                return 'float';
            case Variable::TYPE_BOOLEAN:
                return 'bool';
            case Variable::TYPE_STRING:
                return 'string';
            case Variable::TYPE_ARRAY:
                return 'array';
            case Variable::TYPE_OBJECT:
                return 'object';
            case Variable::TYPE_RESOURCE:
                return 'resource';
            default:
                return 'unknown type';
        }
    }
}
