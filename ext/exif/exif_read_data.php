<?php

declare(strict_types=1);

namespace PHPCompiler\ext\exif;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPCompiler\ext\standard\VmImage;
use PHPCompiler\ext\standard\VmStreamOpenFailure;
use PHPCompiler\ext\standard\VmStreamPath;
use PHPCompiler\ext\standard\VmString;
use PHPLLVM\Value;

/**
 * exif_read_data() — parsed EXIF IFD0 metadata (ext/exif/exif.c; #3400).
 *
 * @see https://github.com/php/php-src/blob/master/ext/exif/exif.c PHP_FUNCTION(exif_read_data)
 */
final class exif_read_data extends Internal
{
    public function __construct()
    {
        parent::__construct('exif_read_data');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1) {
            throw new \ArgumentCountError(\sprintf(
                'exif_read_data() expects at least 1 argument, %d given',
                $argc
            ));
        }
        // php-src stub arity 4: file, required_sections=, as_arrays=, read_thumbnail= (#23605).
        if ($argc > 4) {
            throw new \ArgumentCountError(\sprintf(
                'exif_read_data() expects at most 4 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $filename = VmStreamPath::coerceNonEmptyPathArgForFrame(
            $frame,
            0,
            'exif_read_data',
            'file',
            VmString::emptyStringArgValueErrorMessageCannot('exif_read_data', 0, 'file')
        );
        $data = VmExifRead::readData($filename);
        if (false === $data) {
            if (!VmImage::pathPayloadReadable($filename)) {
                VmStreamOpenFailure::warnFailedToOpen($frame, 'exif_read_data', $filename);
            } else {
                VmExifWarning::warnFileNotSupported($frame, 'exif_read_data', $filename);
            }
            $frame->returnVar->bool(false);

            return;
        }
        $ht = new HashTable();
        foreach ($data as $key => $value) {
            $ht->add((string) $key, self::exifValueToVariable($value));
        }
        $frame->returnVar->array($ht);
    }

    /**
     * @param array<string, int|string>|int|string $value
     */
    private static function exifValueToVariable(array|int|string $value): Variable
    {
        $slot = new Variable();
        if (\is_array($value)) {
            $nested = new HashTable();
            foreach ($value as $nestedKey => $nestedValue) {
                $nested->add((string) $nestedKey, self::exifValueToVariable($nestedValue));
            }
            $slot->array($nested);

            return $slot;
        }
        if (\is_int($value)) {
            $slot->int($value);
        } else {
            $slot->string($value);
        }

        return $slot;
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('exif_read_data() is not implemented for JIT in this compiler build (issue #3400)');
    }
}
