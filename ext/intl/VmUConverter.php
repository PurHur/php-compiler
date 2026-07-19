<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCfg\Func as CfgFunc;
use PHPCompiler\ext\iconv\CharsetEngine;
use PHPCompiler\ext\iconv\VmIconv;
use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\ReflectionSupport;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/**
 * UConverter construct/convert — charset conversion OOP API (php-src ext/intl/converter; #6171 / #20770).
 *
 * Conversion uses {@see CharsetEngine} / {@see VmIconv} (PHP-in-PHP); no ICU ucnv_* FFI in v1.
 * Encoding introspection + subst chars + reasonText mirror php-src converter.c without C runtime growth.
 */
final class VmUConverter
{
    public const CLASS_LC = 'uconverter';

    /** ICU U_FILE_ACCESS_ERROR — unknown converter name on construct. */
    public const U_FILE_ACCESS_ERROR = 4;

    /** ICU U_INVALID_STATE_ERROR — convert after failed open. */
    public const U_INVALID_STATE_ERROR = 27;

    /** ICU U_INVALID_CHAR_FOUND — illegal input sequence. */
    public const U_INVALID_CHAR_FOUND = 10;

    /** @see unicode/ucnv_err.h UCNV_UNASSIGNED … UCNV_CLONE (php-src UConverter::REASON_*). */
    public const REASON_UNASSIGNED = 0;
    public const REASON_ILLEGAL = 1;
    public const REASON_IRREGULAR = 2;
    public const REASON_RESET = 3;
    public const REASON_CLOSE = 4;
    public const REASON_CLONE = 5;

    /**
     * @var array<int, array{
     *     dest: string,
     *     src: string,
     *     destOk: bool,
     *     srcOk: bool,
     *     substChars: string,
     *     errorCode: int,
     *     errorMessage: string,
     *     openOk: bool
     * }>
     */
    private static array $state = [];

    /** @return array<string, int> */
    public static function classConstants(): array
    {
        return [
            'REASON_UNASSIGNED' => self::REASON_UNASSIGNED,
            'REASON_ILLEGAL' => self::REASON_ILLEGAL,
            'REASON_IRREGULAR' => self::REASON_IRREGULAR,
            'REASON_RESET' => self::REASON_RESET,
            'REASON_CLOSE' => self::REASON_CLOSE,
            'REASON_CLONE' => self::REASON_CLONE,
        ];
    }

    public static function registerClass(Context $ctx): void
    {
        if (isset($ctx->classes[self::CLASS_LC])) {
            return;
        }

        $entry = new ClassEntry('UConverter');
        $entry->isInternal = true;
        foreach (self::classConstants() as $name => $value) {
            $lc = strtolower($name);
            $const = new Variable(Variable::TYPE_INTEGER);
            $const->int($value);
            $entry->constants[$lc] = $const;
            $entry->constNames[$lc] = $name;
        }
        $pub = CfgFunc::FLAG_PUBLIC;
        $pubStatic = $pub | CfgFunc::FLAG_STATIC;
        $entry->constructor = new UConverterConstruct();
        $entry->methods['__construct'] = $entry->constructor;
        $entry->methodVisibility['__construct'] = $pub;
        $methods = [
            'convert' => [new UConverterConvert(), 'convert', false],
            'geterrorcode' => [new UConverterGetErrorCode(), 'getErrorCode', false],
            'geterrormessage' => [new UConverterGetErrorMessage(), 'getErrorMessage', false],
            'getsourceencoding' => [new UConverterGetSourceEncoding(), 'getSourceEncoding', false],
            'getdestinationencoding' => [new UConverterGetDestinationEncoding(), 'getDestinationEncoding', false],
            'getsubstchars' => [new UConverterGetSubstChars(), 'getSubstChars', false],
            'setsubstchars' => [new UConverterSetSubstChars(), 'setSubstChars', false],
            'reasontext' => [new UConverterReasonText(), 'reasonText', true],
            'transcode' => [new UConverterTranscode(), 'transcode', true],
        ];
        foreach ($methods as $lc => [$handler, $name, $static]) {
            $entry->methods[$lc] = $handler;
            $entry->methodVisibility[$lc] = $static ? $pubStatic : $pub;
            $entry->methodNames[$lc] = $name;
        }
        $ctx->classes[self::CLASS_LC] = $entry;
    }

    /**
     * UConverter::transcode() — one-shot charset conversion (php-src ext/intl/converter; #6401).
     */
    public static function transcode(
        string $str,
        string $toEncoding,
        string $fromEncoding,
        ?array $options = null
    ): string|false {
        unset($options);

        return VmIconv::iconv($fromEncoding, $toEncoding, $str);
    }

    public static function isUConverterObject(?ObjectEntry $object): bool
    {
        return null !== $object && self::CLASS_LC === strtolower($object->class->name);
    }

    public static function construct(ObjectEntry $object, string $destination, ?string $source): void
    {
        $dest = '' !== $destination ? $destination : 'UTF-8';
        $src = null !== $source && '' !== $source ? $source : 'UTF-8';
        $destOk = null !== CharsetEngine::parseEncodingSpec(VmIconv::resolveIconvEncoding($dest, false));
        $srcOk = null !== CharsetEngine::parseEncodingSpec(VmIconv::resolveIconvEncoding($src, true));
        $openOk = $destOk && $srcOk;
        self::$state[$object->id] = [
            'dest' => $dest,
            'src' => $src,
            'destOk' => $destOk,
            'srcOk' => $srcOk,
            'substChars' => $srcOk ? self::defaultSubstChars($src) : '',
            'errorCode' => $openOk ? IntlError::U_ZERO_ERROR : self::U_FILE_ACCESS_ERROR,
            'errorMessage' => $openOk
                ? 'U_ZERO_ERROR'
                : 'ucnv_open() returned error 4: U_FILE_ACCESS_ERROR: U_FILE_ACCESS_ERROR',
            'openOk' => $openOk,
        ];
        $object->constructed = true;
    }

    public static function convert(ObjectEntry $object, string $str, bool $reverse = false): string|false
    {
        $state = self::$state[$object->id] ?? null;
        if (null === $state) {
            throw new \Error('UConverter::convert() called on uninitialized UConverter');
        }
        if (!$state['openOk']) {
            self::$state[$object->id]['errorCode'] = self::U_INVALID_STATE_ERROR;
            self::$state[$object->id]['errorMessage'] = 'Internal converters not initialized: U_INVALID_STATE_ERROR';

            return false;
        }
        $from = $reverse ? $state['dest'] : $state['src'];
        $to = $reverse ? $state['src'] : $state['dest'];
        $result = VmIconv::iconv($from, $to, $str);
        if (false === $result) {
            self::$state[$object->id]['errorCode'] = self::U_INVALID_CHAR_FOUND;
            self::$state[$object->id]['errorMessage'] = 'Invalid character found: U_INVALID_CHAR_FOUND';

            return false;
        }
        self::$state[$object->id]['errorCode'] = IntlError::U_ZERO_ERROR;
        self::$state[$object->id]['errorMessage'] = 'U_ZERO_ERROR';

        return $result;
    }

    public static function getErrorCode(ObjectEntry $object): int
    {
        $state = self::$state[$object->id] ?? null;
        if (null === $state) {
            return IntlError::U_ZERO_ERROR;
        }

        return $state['errorCode'];
    }

    public static function getErrorMessage(ObjectEntry $object): string
    {
        $state = self::$state[$object->id] ?? null;
        if (null === $state) {
            return 'U_ZERO_ERROR';
        }

        return $state['errorMessage'];
    }

    /**
     * UConverter::getSourceEncoding() — php-src converter.c php_converter_do_get_encoding (#20770).
     */
    public static function getSourceEncoding(ObjectEntry $object): ?string
    {
        $state = self::$state[$object->id] ?? null;
        if (null === $state || !$state['srcOk']) {
            return null;
        }

        return $state['src'];
    }

    /**
     * UConverter::getDestinationEncoding() — php-src converter.c (#20770).
     */
    public static function getDestinationEncoding(ObjectEntry $object): ?string
    {
        $state = self::$state[$object->id] ?? null;
        if (null === $state || !$state['destOk']) {
            return null;
        }

        return $state['dest'];
    }

    /**
     * UConverter::getSubstChars() — php-src converter.c (#20770).
     */
    public static function getSubstChars(ObjectEntry $object): ?string
    {
        $state = self::$state[$object->id] ?? null;
        if (null === $state || !$state['srcOk']) {
            return null;
        }

        return $state['substChars'];
    }

    /**
     * UConverter::setSubstChars() — php-src converter.c ucnv_setSubstChars (#20770).
     *
     * Without ICU, approximate charset acceptance: single-byte converters reject multi-byte
     * substitution sequences (matches Zend ISO-8859-1 vs UTF-8 behavior).
     */
    public static function setSubstChars(ObjectEntry $object, string $chars): bool
    {
        $state = self::$state[$object->id] ?? null;
        if (null === $state) {
            throw new \Error('UConverter::setSubstChars() called on uninitialized UConverter');
        }
        if (!$state['srcOk'] || !$state['destOk']) {
            self::$state[$object->id]['errorCode'] = self::U_INVALID_STATE_ERROR;
            self::$state[$object->id]['errorMessage'] = 'Internal converters not initialized: U_INVALID_STATE_ERROR';

            return false;
        }
        $len = \strlen($chars);
        if ($len < 1 || $len > 4) {
            return false;
        }
        if ($len > 1 && (self::isSingleByteCharset($state['src']) || self::isSingleByteCharset($state['dest']))) {
            return false;
        }
        self::$state[$object->id]['substChars'] = $chars;
        self::$state[$object->id]['errorCode'] = IntlError::U_ZERO_ERROR;
        self::$state[$object->id]['errorMessage'] = 'U_ZERO_ERROR';

        return true;
    }

    /**
     * UConverter::reasonText() — php-src converter.c UCNV_REASON_CASE (#20770).
     */
    public static function reasonText(int $reason): string
    {
        return match ($reason) {
            self::REASON_UNASSIGNED => 'REASON_UNASSIGNED',
            self::REASON_ILLEGAL => 'REASON_ILLEGAL',
            self::REASON_IRREGULAR => 'REASON_IRREGULAR',
            self::REASON_RESET => 'REASON_RESET',
            self::REASON_CLOSE => 'REASON_CLOSE',
            self::REASON_CLONE => 'REASON_CLONE',
            default => throw new \ValueError(
                'UConverter::reasonText(): Argument #1 ($reason) must be a UConverter::REASON_* constant'
            ),
        };
    }

    public static function coerceIntArg(Variable $var, string $function, int $position, string $name): int
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_INTEGER === $var->type) {
            return $var->toInt();
        }
        if (Variable::TYPE_FLOAT === $var->type) {
            return (int) $var->toFloat();
        }
        if (Variable::TYPE_BOOLEAN === $var->type) {
            return $var->toBool() ? 1 : 0;
        }
        if (Variable::TYPE_STRING === $var->type && is_numeric($var->toString())) {
            return (int) $var->toString();
        }
        throw new \TypeError(\sprintf(
            '%s(): Argument #%d ($%s) must be of type int, %s given',
            $function,
            $position + 1,
            $name,
            ReflectionSupport::valueTypeLabelPublic($var)
        ));
    }

    /** ICU default subst: UTF/UCS sources use U+FFFD; single-byte sources use 0x1A. */
    private static function defaultSubstChars(string $srcEncoding): string
    {
        return self::isUnicodeCharset($srcEncoding) ? "\xEF\xBF\xBD" : "\x1a";
    }

    private static function isUnicodeCharset(string $encoding): bool
    {
        $n = strtoupper(str_replace(['-', '_', ' '], '', $encoding));

        return str_contains($n, 'UTF') || str_contains($n, 'UCS') || str_starts_with($n, 'GB18030');
    }

    private static function isSingleByteCharset(string $encoding): bool
    {
        return !self::isUnicodeCharset($encoding);
    }

    public static function requireReceiver(Variable $var, string $label): ObjectEntry
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $var->type || !self::isUConverterObject($var->toObject())) {
            throw new \Error($label.' called on incompatible object');
        }
        $object = $var->toObject();
        if (!$object->constructed) {
            throw new \Error($label.' called on uninitialized UConverter');
        }

        return $object;
    }
}

/** UConverter::__construct() — php-src ext/intl/converter (#6171). */
final class UConverterConstruct extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1) {
            throw new \LogicException('UConverter::__construct() called without $this');
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type) {
            throw new \TypeError('UConverter::__construct() must be called on UConverter');
        }
        $destination = 'UTF-8';
        $source = null;
        if ($argc >= 2) {
            $destination = VmString::coerceStringBuiltinArg(
                $frame->calledArgs[1],
                'UConverter::__construct',
                0,
                'destination_encoding'
            );
        }
        if ($argc >= 3) {
            $source = VmString::coerceStringBuiltinArg(
                $frame->calledArgs[2],
                'UConverter::__construct',
                1,
                'source_encoding'
            );
        }
        if ($argc > 3) {
            throw new \ArgumentCountError(\sprintf(
                'UConverter::__construct() expects at most 2 arguments, %d given',
                $argc - 1
            ));
        }
        VmUConverter::construct($receiver->toObject(), $destination, $source);
    }
}

/** UConverter::convert() — php-src ext/intl/converter (#6171). */
final class UConverterConvert extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('convert');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \ArgumentCountError(\sprintf(
                'UConverter::convert() expects at least 1 argument, %d given',
                max(0, $argc - 1)
            ));
        }
        $object = VmUConverter::requireReceiver($frame->calledArgs[0], 'UConverter::convert()');
        $str = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[1],
            'UConverter::convert',
            0,
            'str'
        );
        $reverse = false;
        if (3 === $argc) {
            $revVar = $frame->calledArgs[2]->resolveIndirect();
            $reverse = Variable::TYPE_NULL !== $revVar->type && $revVar->toBool();
        }
        $result = VmUConverter::convert($object, $str, $reverse);
        if (null === $frame->returnVar) {
            return;
        }
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($result);
    }
}

/** UConverter::getErrorCode() — php-src ext/intl/converter (#6171). */
final class UConverterGetErrorCode extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getErrorCode');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'UConverter::getErrorCode() expects exactly 0 arguments, %d given',
                max(0, $argc - 1)
            ));
        }
        $object = VmUConverter::requireReceiver($frame->calledArgs[0], 'UConverter::getErrorCode()');
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int(VmUConverter::getErrorCode($object));
    }
}

/** UConverter::transcode() — php-src ext/intl/converter/converter.c (#6401). */
final class UConverterTranscode extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('transcode');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 3) {
            throw new \ArgumentCountError(\sprintf(
                'UConverter::transcode() expects at least 3 arguments, %d given',
                $argc
            ));
        }
        if ($argc > 4) {
            throw new \ArgumentCountError(\sprintf(
                'UConverter::transcode() expects at most 4 arguments, %d given',
                $argc
            ));
        }
        $str = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            'UConverter::transcode',
            0,
            'str'
        );
        $toEncoding = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[1],
            'UConverter::transcode',
            1,
            'toEncoding'
        );
        $fromEncoding = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[2],
            'UConverter::transcode',
            2,
            'fromEncoding'
        );
        if (4 === $argc) {
            $optVar = $frame->calledArgs[3]->resolveIndirect();
            if (Variable::TYPE_NULL !== $optVar->type && Variable::TYPE_ARRAY !== $optVar->type) {
                throw new \TypeError(\sprintf(
                    'UConverter::transcode(): Argument #4 ($options) must be of type array, %s given',
                    ReflectionSupport::valueTypeLabelPublic($optVar)
                ));
            }
        }
        if (null === $frame->returnVar) {
            return;
        }
        $result = VmUConverter::transcode($str, $toEncoding, $fromEncoding);
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($result);
    }
}

/** UConverter::getErrorMessage() — php-src ext/intl/converter (#6171). */
final class UConverterGetErrorMessage extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getErrorMessage');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'UConverter::getErrorMessage() expects exactly 0 arguments, %d given',
                max(0, $argc - 1)
            ));
        }
        $object = VmUConverter::requireReceiver($frame->calledArgs[0], 'UConverter::getErrorMessage()');
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->string(VmUConverter::getErrorMessage($object));
    }
}

/** UConverter::getSourceEncoding() — php-src ext/intl/converter (#20770). */
final class UConverterGetSourceEncoding extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getSourceEncoding');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'UConverter::getSourceEncoding() expects exactly 0 arguments, %d given',
                max(0, $argc - 1)
            ));
        }
        $object = VmUConverter::requireReceiver($frame->calledArgs[0], 'UConverter::getSourceEncoding()');
        if (null === $frame->returnVar) {
            return;
        }
        $encoding = VmUConverter::getSourceEncoding($object);
        if (null === $encoding) {
            $frame->returnVar->null();

            return;
        }
        $frame->returnVar->string($encoding);
    }
}

/** UConverter::getDestinationEncoding() — php-src ext/intl/converter (#20770). */
final class UConverterGetDestinationEncoding extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getDestinationEncoding');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'UConverter::getDestinationEncoding() expects exactly 0 arguments, %d given',
                max(0, $argc - 1)
            ));
        }
        $object = VmUConverter::requireReceiver($frame->calledArgs[0], 'UConverter::getDestinationEncoding()');
        if (null === $frame->returnVar) {
            return;
        }
        $encoding = VmUConverter::getDestinationEncoding($object);
        if (null === $encoding) {
            $frame->returnVar->null();

            return;
        }
        $frame->returnVar->string($encoding);
    }
}

/** UConverter::getSubstChars() — php-src ext/intl/converter (#20770). */
final class UConverterGetSubstChars extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getSubstChars');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'UConverter::getSubstChars() expects exactly 0 arguments, %d given',
                max(0, $argc - 1)
            ));
        }
        $object = VmUConverter::requireReceiver($frame->calledArgs[0], 'UConverter::getSubstChars()');
        if (null === $frame->returnVar) {
            return;
        }
        $chars = VmUConverter::getSubstChars($object);
        if (null === $chars) {
            $frame->returnVar->null();

            return;
        }
        $frame->returnVar->string($chars);
    }
}

/** UConverter::setSubstChars() — php-src ext/intl/converter (#20770). */
final class UConverterSetSubstChars extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('setSubstChars');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'UConverter::setSubstChars() expects exactly 1 argument, %d given',
                max(0, $argc - 1)
            ));
        }
        $object = VmUConverter::requireReceiver($frame->calledArgs[0], 'UConverter::setSubstChars()');
        $chars = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[1],
            'UConverter::setSubstChars',
            0,
            'chars'
        );
        $ok = VmUConverter::setSubstChars($object, $chars);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool($ok);
    }
}

/** UConverter::reasonText() — php-src ext/intl/converter (#20770). */
final class UConverterReasonText extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('reasonText');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'UConverter::reasonText() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        $reason = VmUConverter::coerceIntArg(
            $frame->calledArgs[0],
            'UConverter::reasonText',
            0,
            'reason'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->string(VmUConverter::reasonText($reason));
    }
}
