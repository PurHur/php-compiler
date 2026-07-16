<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\ext\standard\VmDate;
use PHPCompiler\ext\standard\VmDateTimeNative;
use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\DateTimeSupport;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;
use PHPCfg\Func as CfgFunc;

/**
 * IntlTimeZone — Gregorian/zoneinfo subset without full ICU (#6151).
 *
 * php-src: ext/intl/timezone/timezone_class.c, timezone_methods.c, timezone.stub.php
 */
final class VmIntlTimeZone
{
    public const CLASS_LC = 'intltimezone';

    public const DISPLAY_SHORT = 1;
    public const DISPLAY_LONG = 2;
    public const DISPLAY_SHORT_GENERIC = 3;
    public const DISPLAY_LONG_GENERIC = 4;
    public const DISPLAY_SHORT_GMT = 5;
    public const DISPLAY_LONG_GMT = 6;
    public const DISPLAY_SHORT_COMMONLY_USED = 7;
    public const DISPLAY_GENERIC_LOCATION = 8;

    public const TYPE_ANY = 0;
    public const TYPE_CANONICAL = 1;
    public const TYPE_CANONICAL_LOCATION = 2;

    /** @var array<int, array{id: string}> */
    private static array $state = [];

    /** @return array<string, int> */
    public static function classConstants(): array
    {
        return [
            'DISPLAY_SHORT' => self::DISPLAY_SHORT,
            'DISPLAY_LONG' => self::DISPLAY_LONG,
            'DISPLAY_SHORT_GENERIC' => self::DISPLAY_SHORT_GENERIC,
            'DISPLAY_LONG_GENERIC' => self::DISPLAY_LONG_GENERIC,
            'DISPLAY_SHORT_GMT' => self::DISPLAY_SHORT_GMT,
            'DISPLAY_LONG_GMT' => self::DISPLAY_LONG_GMT,
            'DISPLAY_SHORT_COMMONLY_USED' => self::DISPLAY_SHORT_COMMONLY_USED,
            'DISPLAY_GENERIC_LOCATION' => self::DISPLAY_GENERIC_LOCATION,
            'TYPE_ANY' => self::TYPE_ANY,
            'TYPE_CANONICAL' => self::TYPE_CANONICAL,
            'TYPE_CANONICAL_LOCATION' => self::TYPE_CANONICAL_LOCATION,
        ];
    }

    public static function registerClass(Context $ctx): void
    {
        if (isset($ctx->classes[self::CLASS_LC])) {
            return;
        }

        $entry = new ClassEntry('IntlTimeZone');
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
        $methods = [
            'createtimezone' => [new IntlTimeZoneCreateTimeZone(), 'createTimeZone', $pubStatic],
            'createdefault' => [new IntlTimeZoneCreateDefault(), 'createDefault', $pubStatic],
            'getid' => [new IntlTimeZoneGetID(), 'getID', $pub],
        ];
        foreach ($methods as $lc => [$handler, $name, $vis]) {
            $entry->methods[$lc] = $handler;
            $entry->methodVisibility[$lc] = $vis;
            $entry->methodNames[$lc] = $name;
        }
        $ctx->classes[self::CLASS_LC] = $entry;
    }

    public static function isTimeZoneObject(?ObjectEntry $object): bool
    {
        return null !== $object && self::CLASS_LC === strtolower($object->class->name);
    }

    public static function idOf(ObjectEntry $object): string
    {
        return self::$state[$object->id]['id'] ?? 'UTC';
    }

    public static function createFromId(Context $ctx, string $id): ObjectEntry
    {
        if (!isset($ctx->classes[self::CLASS_LC])) {
            throw new \Error('Class "IntlTimeZone" not found');
        }
        $canonical = self::resolveTimezoneId($id);
        $object = new ObjectEntry($ctx->classes[self::CLASS_LC]);
        $object->constructed = true;
        self::$state[$object->id] = ['id' => $canonical];
        IntlError::clear();

        return $object;
    }

    public static function createDefault(Context $ctx): ObjectEntry
    {
        return self::createFromId($ctx, VmDate::defaultTimezoneGet());
    }

    /**
     * Resolve createInstance / createTimeZone timezone operand (null|string|DateTimeZone|IntlTimeZone).
     */
    public static function resolveTimezoneOperand(Variable $var, Context $ctx, string $function, int $position): string
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_NULL === $var->type) {
            return VmDate::defaultTimezoneGet();
        }
        if (Variable::TYPE_OBJECT === $var->type) {
            $obj = $var->toObject();
            if (self::isTimeZoneObject($obj)) {
                return self::idOf($obj);
            }
            if ('datetimezone' === strtolower($obj->class->name)) {
                return DateTimeSupport::timezoneName($obj);
            }
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($timezone) must be of type IntlTimeZone|DateTimeZone|string|null, %s given',
                $function,
                $position + 1,
                $obj->class->name
            ));
        }

        return self::resolveTimezoneId(
            VmString::coerceStringBuiltinArg($var, $function, $position, 'timezone')
        );
    }

    public static function resolveTimezoneId(string $id): string
    {
        $id = trim($id);
        if ('' === $id) {
            return VmDate::defaultTimezoneGet();
        }
        try {
            return VmDateTimeNative::validateTimezoneId($id);
        } catch (\Throwable) {
            // php-src intltz_create_time_zone — unknown IDs become GMT with error set.
            IntlError::set(
                IntlError::U_ILLEGAL_ARGUMENT_ERROR,
                "intltz_create_time_zone: No such time zone: '".$id."': U_ILLEGAL_ARGUMENT_ERROR"
            );

            return 'GMT';
        }
    }
}

/** IntlTimeZone::createTimeZone() — php-src intltz_create_time_zone (#6151). */
final class IntlTimeZoneCreateTimeZone extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('createTimeZone');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'IntlTimeZone::createTimeZone() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        $id = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            'IntlTimeZone::createTimeZone',
            0,
            'zoneId'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->object(VmIntlTimeZone::createFromId($frame->vmContext, $id));
    }
}

/** IntlTimeZone::createDefault() — php-src intltz_create_default (#6151). */
final class IntlTimeZoneCreateDefault extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('createDefault');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (0 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'IntlTimeZone::createDefault() expects exactly 0 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->object(VmIntlTimeZone::createDefault($frame->vmContext));
    }
}

/** IntlTimeZone::getID() — php-src intltz_get_id (#6151). */
final class IntlTimeZoneGetID extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getID');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'IntlTimeZone::getID() expects exactly 0 arguments, %d given',
                max(0, $argc - 1)
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type
            || !VmIntlTimeZone::isTimeZoneObject($receiver->toObject())) {
            throw new \Error('IntlTimeZone::getID() called on incompatible object');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->string(VmIntlTimeZone::idOf($receiver->toObject()));
    }
}
