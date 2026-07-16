<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCfg\Func as CfgFunc;
use PHPCompiler\ext\iconv\CharsetEngine;
use PHPCompiler\ext\iconv\VmIconv;
use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/**
 * UConverter construct/convert — charset conversion OOP API (php-src ext/intl/converter; #6171).
 *
 * Conversion uses {@see CharsetEngine} / {@see VmIconv} (PHP-in-PHP); no ICU ucnv_* FFI in v1.
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

    /** @var array<int, array{dest: string, src: string, errorCode: int, errorMessage: string, openOk: bool}> */
    private static array $state = [];

    public static function registerClass(Context $ctx): void
    {
        if (isset($ctx->classes[self::CLASS_LC])) {
            return;
        }

        $entry = new ClassEntry('UConverter');
        $entry->isInternal = true;
        $pub = CfgFunc::FLAG_PUBLIC;
        $entry->constructor = new UConverterConstruct();
        $entry->methods['__construct'] = $entry->constructor;
        $entry->methodVisibility['__construct'] = $pub;
        $methods = [
            'convert' => [new UConverterConvert(), 'convert'],
            'geterrorcode' => [new UConverterGetErrorCode(), 'getErrorCode'],
            'geterrormessage' => [new UConverterGetErrorMessage(), 'getErrorMessage'],
        ];
        foreach ($methods as $lc => [$handler, $name]) {
            $entry->methods[$lc] = $handler;
            $entry->methodVisibility[$lc] = $pub;
            $entry->methodNames[$lc] = $name;
        }
        $ctx->classes[self::CLASS_LC] = $entry;
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
