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

        // FILEINFO_NONE / FILEINFO_RAW — libmagic-shaped human descriptions (#19247).
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

    /**
     * Human-readable libmagic descriptions for FILEINFO_NONE / FILEINFO_RAW.
     *
     * php-src: ext/fileinfo/libmagic — prefer structured parsers over MIME alone (#19247).
     */
    private static function humanDescription(string $mime, string $data): string
    {
        if ('' === $data) {
            return 'empty';
        }

        $elf = self::describeElf($data);
        if (null !== $elf) {
            return $elf;
        }
        $png = self::describePng($data);
        if (null !== $png) {
            return $png;
        }
        // Bare PNG signature without IHDR — Zend/libmagic reports "data".
        if (\strlen($data) >= 8 && 0 === \strncmp($data, "\x89PNG\r\n\x1a\n", 8)) {
            return 'data';
        }
        $gif = self::describeGif($data);
        if (null !== $gif) {
            return $gif;
        }
        $pdf = self::describePdf($data);
        if (null !== $pdf) {
            return $pdf;
        }
        $jpeg = self::describeJpeg($data);
        if (null !== $jpeg) {
            return $jpeg;
        }

        switch ($mime) {
            case 'text/x-php':
                return false === \strpos($data, "\n") && false === \strpos($data, "\r")
                    ? 'PHP script, ASCII text, with no line terminators'
                    : 'PHP script, ASCII text';
            case 'text/html':
                return 'HTML document, ASCII text';
            case 'text/plain':
                return 'ASCII text';
            case 'image/jpeg':
                return 'JPEG image data';
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

    private static function describePdf(string $data): ?string
    {
        if (\strlen($data) < 5 || 0 !== \strncmp($data, '%PDF-', 5)) {
            return null;
        }
        if (1 === \preg_match('/^%PDF-(\d+\.\d+)/', $data, $m)) {
            return 'PDF document, version '.$m[1];
        }

        return 'PDF document';
    }

    private static function describeGif(string $data): ?string
    {
        if (\strlen($data) < 6) {
            return null;
        }
        $ver = \substr($data, 0, 6);
        if ('GIF87a' !== $ver && 'GIF89a' !== $ver) {
            return null;
        }

        // Trailing comma matches incomplete-dimension libmagic output.
        return 'GIF image data, version '.\substr($ver, 3).',';
    }

    private static function describeJpeg(string $data): ?string
    {
        if (\strlen($data) < 3 || 0 !== \strncmp($data, "\xff\xd8\xff", 3)) {
            return null;
        }
        // JFIF APP0: FF E0 len(2) "JFIF\0"
        if (\strlen($data) >= 11
            && "\xff\xe0" === \substr($data, 3, 2)
            && 'JFIF' === \substr($data, 7, 4)) {
            $segLen = (\ord($data[5]) << 8) | \ord($data[6]);

            return 'JPEG image data, JFIF standard, segment length '.$segLen;
        }

        return 'JPEG image data';
    }

    private static function describePng(string $data): ?string
    {
        if (\strlen($data) < 8 || 0 !== \strncmp($data, "\x89PNG\r\n\x1a\n", 8)) {
            return null;
        }
        $pos = 8;
        $len = \strlen($data);
        while ($pos + 12 <= $len) {
            $chunkLen = self::readU32Be($data, $pos);
            $type = \substr($data, $pos + 4, 4);
            $pos += 8;
            if ($pos + $chunkLen + 4 > $len) {
                break;
            }
            if ('IHDR' === $type && $chunkLen >= 13) {
                $w = self::readU32Be($data, $pos);
                $h = self::readU32Be($data, $pos + 4);
                $bitDepth = \ord($data[$pos + 8]);
                $colorType = \ord($data[$pos + 9]);
                $interlace = \ord($data[$pos + 12]);
                $color = self::pngColorName($colorType);
                $interlaceLabel = 0 === $interlace ? 'non-interlaced' : 'interlaced';

                return \sprintf(
                    'PNG image data, %d x %d, %d-bit/color %s, %s',
                    $w,
                    $h,
                    $bitDepth,
                    $color,
                    $interlaceLabel
                );
            }
            $pos += $chunkLen + 4;
        }

        return null;
    }

    private static function pngColorName(int $colorType): string
    {
        switch ($colorType) {
            case 0:
                return 'Gray';
            case 2:
                return 'RGB';
            case 3:
                return 'colormap';
            case 4:
                return 'GrayAlpha';
            case 6:
                return 'RGBA';
            default:
                return 'Unknown';
        }
    }

    /**
     * ELF identification + e_type/e_machine (+ DF_1_PIE when present).
     *
     * @see https://github.com/php/php-src/tree/master/ext/fileinfo/libmagic
     */
    private static function describeElf(string $data): ?string
    {
        $len = \strlen($data);
        if ($len < 16 || "\x7fELF" !== \substr($data, 0, 4)) {
            return null;
        }

        $eiClass = \ord($data[4]); // 1=32, 2=64
        $eiData = \ord($data[5]); // 1=LSB, 2=MSB
        $eiVersion = \ord($data[6]);
        $eiOsAbi = \ord($data[7]);
        $is64 = 2 === $eiClass;
        $le = 1 === $eiData;
        $classLabel = 1 === $eiClass ? '32-bit' : (2 === $eiClass ? '64-bit' : 'invalid class');
        $endianLabel = 1 === $eiData ? 'LSB' : (2 === $eiData ? 'MSB' : 'invalid endian');

        $ehSize = $is64 ? 64 : 52;
        $eType = 0;
        $eMachine = 0;
        $eVersion = 0;
        if ($len >= ($is64 ? 28 : 24)) {
            $eType = self::readU16($data, 16, $le);
            $eMachine = self::readU16($data, 18, $le);
            $eVersion = self::readU32($data, 20, $le);
        }

        $typeLabel = self::elfTypeLabel($eType, $data, $is64, $le, $ehSize);
        $machineLabel = self::elfMachineLabel($eMachine);
        if (0 === $eVersion || 0 === $eiVersion) {
            $versionLabel = 'invalid version';
        } else {
            $versionLabel = 'version '.$eVersion;
        }
        $osAbiLabel = 0 === $eiOsAbi ? 'SYSV' : 'ABI '.$eiOsAbi;

        return \sprintf(
            'ELF %s %s %s, %s, %s (%s)',
            $classLabel,
            $endianLabel,
            $typeLabel,
            $machineLabel,
            $versionLabel,
            $osAbiLabel
        );
    }

    private static function elfTypeLabel(int $eType, string $data, bool $is64, bool $le, int $ehSize): string
    {
        switch ($eType) {
            case 0:
                return 'no file type';
            case 1:
                return 'relocatable';
            case 2:
                return 'executable';
            case 3:
                return self::elfHasPieFlag($data, $is64, $le, $ehSize)
                    ? 'pie executable'
                    : 'shared object';
            case 4:
                return 'core file';
            default:
                return 'unknown type '.$eType;
        }
    }

    private static function elfMachineLabel(int $eMachine): string
    {
        switch ($eMachine) {
            case 0:
                return 'no machine';
            case 3:
                return 'Intel 80386';
            case 40:
                return 'ARM';
            case 62:
                return 'x86-64';
            case 183:
                return 'AArch64';
            default:
                return 'machine '.$eMachine;
        }
    }

    /** Scan PT_DYNAMIC for DT_FLAGS_1 / DF_1_PIE (libmagic pie heuristic). */
    private static function elfHasPieFlag(string $data, bool $is64, bool $le, int $ehSize): bool
    {
        $len = \strlen($data);
        if ($len < $ehSize) {
            return false;
        }
        $phOff = $is64 ? self::readU64($data, 32, $le) : self::readU32($data, 28, $le);
        $phEntSize = self::readU16($data, $is64 ? 54 : 42, $le);
        $phNum = self::readU16($data, $is64 ? 56 : 44, $le);
        if (0 === $phOff || 0 === $phEntSize || 0 === $phNum || $phNum > 256) {
            return false;
        }
        $dynOff = 0;
        $dynFilesz = 0;
        for ($i = 0; $i < $phNum; ++$i) {
            $base = $phOff + $i * $phEntSize;
            if ($base + $phEntSize > $len) {
                break;
            }
            $pType = self::readU32($data, $base, $le);
            if (2 !== $pType) { // PT_DYNAMIC
                continue;
            }
            $dynOff = $is64
                ? self::readU64($data, $base + 8, $le)
                : self::readU32($data, $base + 4, $le);
            $dynFilesz = $is64
                ? self::readU64($data, $base + 32, $le)
                : self::readU32($data, $base + 16, $le);
            break;
        }
        if (0 === $dynOff || 0 === $dynFilesz) {
            return false;
        }
        $entrySize = $is64 ? 16 : 8;
        $count = (int) ($dynFilesz / $entrySize);
        if ($count > 512) {
            $count = 512;
        }
        for ($i = 0; $i < $count; ++$i) {
            $off = $dynOff + $i * $entrySize;
            if ($off + $entrySize > $len) {
                break;
            }
            $tag = $is64
                ? self::readU64($data, $off, $le)
                : self::readU32($data, $off, $le);
            if (0 === $tag) {
                break;
            }
            if (0x6ffffffb === $tag) { // DT_FLAGS_1
                $val = $is64
                    ? self::readU64($data, $off + 8, $le)
                    : self::readU32($data, $off + 4, $le);
                if (0 !== ($val & 0x08000000)) { // DF_1_PIE
                    return true;
                }
            }
        }

        return false;
    }

    private static function readU16(string $data, int $off, bool $le): int
    {
        $b0 = \ord($data[$off]);
        $b1 = \ord($data[$off + 1]);

        return $le ? ($b0 | ($b1 << 8)) : (($b0 << 8) | $b1);
    }

    private static function readU32(string $data, int $off, bool $le): int
    {
        $b0 = \ord($data[$off]);
        $b1 = \ord($data[$off + 1]);
        $b2 = \ord($data[$off + 2]);
        $b3 = \ord($data[$off + 3]);
        if ($le) {
            return $b0 | ($b1 << 8) | ($b2 << 16) | ($b3 << 24);
        }

        return ($b0 << 24) | ($b1 << 16) | ($b2 << 8) | $b3;
    }

    private static function readU32Be(string $data, int $off): int
    {
        return self::readU32($data, $off, false);
    }

    private static function readU64(string $data, int $off, bool $le): int
    {
        // File offsets fit in 32-bit for CI fixtures; discard high half.
        return $le
            ? self::readU32($data, $off, true)
            : self::readU32($data, $off + 4, false);
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
