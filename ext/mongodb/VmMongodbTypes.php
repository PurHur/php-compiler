<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mongodb;

use PHPCfg\Func as CfgFunc;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\StringableSupport;
use PHPCompiler\VM\Variable;
use PHPCompiler\ext\standard\VmString;

/**
 * MongoDB\BSON\* + remaining MongoDB\Driver\* value types (#27875, PECL mongodb).
 *
 * php-src / PECL: mongodb/mongo-php-driver src/MongoDB/BSON/* + src/MongoDB/Driver/*
 */
final class VmMongodbTypes
{
    public const OBJECT_ID_LC = 'mongodb\\bson\\objectid';
    public const UTC_DATE_TIME_LC = 'mongodb\\bson\\utcdatetime';
    public const BINARY_LC = 'mongodb\\bson\\binary';
    public const REGEX_LC = 'mongodb\\bson\\regex';
    public const DECIMAL128_LC = 'mongodb\\bson\\decimal128';
    public const TIMESTAMP_LC = 'mongodb\\bson\\timestamp';
    public const COMMAND_LC = 'mongodb\\driver\\command';
    public const READ_PREFERENCE_LC = 'mongodb\\driver\\readpreference';
    public const WRITE_CONCERN_LC = 'mongodb\\driver\\writeconcern';
    public const SESSION_LC = 'mongodb\\driver\\session';
    public const SERVER_LC = 'mongodb\\driver\\server';

    /** @var array<int, string> ObjectId hex (24 chars) */
    private static array $objectIds = [];

    /** @var array<int, string> UTCDateTime milliseconds as decimal string */
    private static array $utcDateTimes = [];

    /** @var array<int, array{data: string, type: int}> */
    private static array $binaries = [];

    /** @var array<int, array{pattern: string, flags: string}> */
    private static array $regexes = [];

    /** @var array<int, string> */
    private static array $decimal128 = [];

    /** @var array<int, array{increment: int, timestamp: int}> */
    private static array $timestamps = [];

    /** @var array<int, true> */
    private static array $commands = [];

    /** @var array<int, array{mode: int, modeString: string}> */
    private static array $readPreferences = [];

    /** @var array<int, array{w: int|string, wtimeout: int, journal: ?bool}> */
    private static array $writeConcerns = [];

    private static int $objectIdCounter = 0;

    public static function register(Context $ctx): void
    {
        require_once __DIR__.'/MongodbBsonMethods.php';
        require_once __DIR__.'/MongodbDriverMethods.php';
        self::registerObjectId($ctx);
        self::registerUtcDateTime($ctx);
        self::registerBinary($ctx);
        self::registerRegex($ctx);
        self::registerDecimal128($ctx);
        self::registerTimestamp($ctx);
        self::registerCommand($ctx);
        self::registerReadPreference($ctx);
        self::registerWriteConcern($ctx);
        self::registerFinalShell($ctx, self::SESSION_LC, 'MongoDB\\Driver\\Session');
        self::registerFinalShell($ctx, self::SERVER_LC, 'MongoDB\\Driver\\Server');
    }

    private static function registerFinalShell(Context $ctx, string $lc, string $name): void
    {
        if (isset($ctx->classes[$lc])) {
            return;
        }
        $entry = new ClassEntry($name);
        $entry->isInternal = true;
        $entry->isFinal = true;
        $ctx->classes[$lc] = $entry;
    }

    private static function attachMethods(ClassEntry $entry, array $methods, int $pub): void
    {
        foreach ($methods as $lc => [$handler, $name]) {
            $entry->methods[$lc] = $handler;
            $entry->methodVisibility[$lc] = $pub;
            $entry->methodNames[$lc] = $name;
        }
    }

    private static function registerObjectId(Context $ctx): void
    {
        if (isset($ctx->classes[self::OBJECT_ID_LC])) {
            return;
        }
        $pub = CfgFunc::FLAG_PUBLIC;
        $entry = new ClassEntry('MongoDB\\BSON\\ObjectId');
        $entry->isInternal = true;
        $entry->isFinal = true;
        if (isset($ctx->classes[StringableSupport::INTERFACE_LC])) {
            $entry->interfaces[] = StringableSupport::INTERFACE_LC;
        }
        $ctor = new ObjectIdConstruct();
        $entry->constructor = $ctor;
        self::attachMethods($entry, [
            '__construct' => [$ctor, '__construct'],
            '__tostring' => [new ObjectIdToString(), '__toString'],
            'gettimestamp' => [new ObjectIdGetTimestamp(), 'getTimestamp'],
        ], $pub);
        $ctx->classes[self::OBJECT_ID_LC] = $entry;
    }

    private static function registerUtcDateTime(Context $ctx): void
    {
        if (isset($ctx->classes[self::UTC_DATE_TIME_LC])) {
            return;
        }
        $pub = CfgFunc::FLAG_PUBLIC;
        $entry = new ClassEntry('MongoDB\\BSON\\UTCDateTime');
        $entry->isInternal = true;
        $entry->isFinal = true;
        if (isset($ctx->classes[StringableSupport::INTERFACE_LC])) {
            $entry->interfaces[] = StringableSupport::INTERFACE_LC;
        }
        $ctor = new UtcDateTimeConstruct();
        $entry->constructor = $ctor;
        self::attachMethods($entry, [
            '__construct' => [$ctor, '__construct'],
            '__tostring' => [new UtcDateTimeToString(), '__toString'],
        ], $pub);
        $ctx->classes[self::UTC_DATE_TIME_LC] = $entry;
    }

    private static function registerBinary(Context $ctx): void
    {
        if (isset($ctx->classes[self::BINARY_LC])) {
            return;
        }
        $pub = CfgFunc::FLAG_PUBLIC;
        $entry = new ClassEntry('MongoDB\\BSON\\Binary');
        $entry->isInternal = true;
        $entry->isFinal = true;
        foreach ([
            'TYPE_GENERIC' => 0,
            'TYPE_FUNCTION' => 1,
            'TYPE_OLD_BINARY' => 2,
            'TYPE_UUID_OLD' => 3,
            'TYPE_UUID' => 4,
            'TYPE_MD5' => 5,
            'TYPE_ENCRYPTED' => 6,
            'TYPE_COLUMN' => 7,
            'TYPE_SENSITIVE' => 8,
            'TYPE_USER_DEFINED' => 128,
        ] as $name => $value) {
            $const = new Variable(Variable::TYPE_INTEGER);
            $const->int($value);
            $entry->constants[$name] = $const;
            $entry->constNames[$name] = $name;
        }
        $ctor = new BinaryConstruct();
        $entry->constructor = $ctor;
        self::attachMethods($entry, [
            '__construct' => [$ctor, '__construct'],
            'getdata' => [new BinaryGetData(), 'getData'],
            'gettype' => [new BinaryGetType(), 'getType'],
        ], $pub);
        $ctx->classes[self::BINARY_LC] = $entry;
    }

    private static function registerRegex(Context $ctx): void
    {
        if (isset($ctx->classes[self::REGEX_LC])) {
            return;
        }
        $pub = CfgFunc::FLAG_PUBLIC;
        $entry = new ClassEntry('MongoDB\\BSON\\Regex');
        $entry->isInternal = true;
        $entry->isFinal = true;
        if (isset($ctx->classes[StringableSupport::INTERFACE_LC])) {
            $entry->interfaces[] = StringableSupport::INTERFACE_LC;
        }
        $ctor = new RegexConstruct();
        $entry->constructor = $ctor;
        self::attachMethods($entry, [
            '__construct' => [$ctor, '__construct'],
            'getpattern' => [new RegexGetPattern(), 'getPattern'],
            'getflags' => [new RegexGetFlags(), 'getFlags'],
            '__tostring' => [new RegexToString(), '__toString'],
        ], $pub);
        $ctx->classes[self::REGEX_LC] = $entry;
    }

    private static function registerDecimal128(Context $ctx): void
    {
        if (isset($ctx->classes[self::DECIMAL128_LC])) {
            return;
        }
        $pub = CfgFunc::FLAG_PUBLIC;
        $entry = new ClassEntry('MongoDB\\BSON\\Decimal128');
        $entry->isInternal = true;
        $entry->isFinal = true;
        if (isset($ctx->classes[StringableSupport::INTERFACE_LC])) {
            $entry->interfaces[] = StringableSupport::INTERFACE_LC;
        }
        $ctor = new Decimal128Construct();
        $entry->constructor = $ctor;
        self::attachMethods($entry, [
            '__construct' => [$ctor, '__construct'],
            '__tostring' => [new Decimal128ToString(), '__toString'],
        ], $pub);
        $ctx->classes[self::DECIMAL128_LC] = $entry;
    }

    private static function registerTimestamp(Context $ctx): void
    {
        if (isset($ctx->classes[self::TIMESTAMP_LC])) {
            return;
        }
        $pub = CfgFunc::FLAG_PUBLIC;
        $entry = new ClassEntry('MongoDB\\BSON\\Timestamp');
        $entry->isInternal = true;
        $entry->isFinal = true;
        if (isset($ctx->classes[StringableSupport::INTERFACE_LC])) {
            $entry->interfaces[] = StringableSupport::INTERFACE_LC;
        }
        $ctor = new TimestampConstruct();
        $entry->constructor = $ctor;
        self::attachMethods($entry, [
            '__construct' => [$ctor, '__construct'],
            'getincrement' => [new TimestampGetIncrement(), 'getIncrement'],
            'gettimestamp' => [new TimestampGetTimestamp(), 'getTimestamp'],
            '__tostring' => [new TimestampToString(), '__toString'],
        ], $pub);
        $ctx->classes[self::TIMESTAMP_LC] = $entry;
    }

    private static function registerCommand(Context $ctx): void
    {
        if (isset($ctx->classes[self::COMMAND_LC])) {
            return;
        }
        $pub = CfgFunc::FLAG_PUBLIC;
        $entry = new ClassEntry('MongoDB\\Driver\\Command');
        $entry->isInternal = true;
        $entry->isFinal = true;
        $ctor = new CommandConstruct();
        $entry->constructor = $ctor;
        self::attachMethods($entry, [
            '__construct' => [$ctor, '__construct'],
        ], $pub);
        $ctx->classes[self::COMMAND_LC] = $entry;
    }

    private static function registerReadPreference(Context $ctx): void
    {
        if (isset($ctx->classes[self::READ_PREFERENCE_LC])) {
            return;
        }
        $pub = CfgFunc::FLAG_PUBLIC;
        $entry = new ClassEntry('MongoDB\\Driver\\ReadPreference');
        $entry->isInternal = true;
        $entry->isFinal = true;
        foreach ([
            'RP_PRIMARY' => 1,
            'RP_PRIMARY_PREFERRED' => 5,
            'RP_SECONDARY' => 2,
            'RP_SECONDARY_PREFERRED' => 6,
            'RP_NEAREST' => 10,
        ] as $name => $value) {
            $const = new Variable(Variable::TYPE_INTEGER);
            $const->int($value);
            $entry->constants[$name] = $const;
            $entry->constNames[$name] = $name;
        }
        foreach ([
            'PRIMARY' => 'primary',
            'PRIMARY_PREFERRED' => 'primaryPreferred',
            'SECONDARY' => 'secondary',
            'SECONDARY_PREFERRED' => 'secondaryPreferred',
            'NEAREST' => 'nearest',
        ] as $name => $value) {
            $const = new Variable(Variable::TYPE_STRING);
            $const->string($value);
            $entry->constants[$name] = $const;
            $entry->constNames[$name] = $name;
        }
        $ctor = new ReadPreferenceConstruct();
        $entry->constructor = $ctor;
        self::attachMethods($entry, [
            '__construct' => [$ctor, '__construct'],
            'getmode' => [new ReadPreferenceGetMode(), 'getMode'],
            'getmodestring' => [new ReadPreferenceGetModeString(), 'getModeString'],
        ], $pub);
        $ctx->classes[self::READ_PREFERENCE_LC] = $entry;
    }

    private static function registerWriteConcern(Context $ctx): void
    {
        if (isset($ctx->classes[self::WRITE_CONCERN_LC])) {
            return;
        }
        $pub = CfgFunc::FLAG_PUBLIC;
        $entry = new ClassEntry('MongoDB\\Driver\\WriteConcern');
        $entry->isInternal = true;
        $entry->isFinal = true;
        $majority = new Variable(Variable::TYPE_STRING);
        $majority->string('majority');
        $entry->constants['MAJORITY'] = $majority;
        $entry->constNames['MAJORITY'] = 'MAJORITY';
        $ctor = new WriteConcernConstruct();
        $entry->constructor = $ctor;
        self::attachMethods($entry, [
            '__construct' => [$ctor, '__construct'],
            'getw' => [new WriteConcernGetW(), 'getW'],
            'getwtimeout' => [new WriteConcernGetWtimeout(), 'getWtimeout'],
            'getjournal' => [new WriteConcernGetJournal(), 'getJournal'],
        ], $pub);
        $ctx->classes[self::WRITE_CONCERN_LC] = $entry;
    }

    public static function requireObject(Variable $var, string $label, string $classLc): ObjectEntry
    {
        return VmMongodb::requireReceiver($var, $label, $classLc);
    }

    public static function initObjectId(ObjectEntry $entry, ?string $id): void
    {
        if (null === $id || '' === $id) {
            $hex = self::generateObjectIdHex();
        } else {
            if (!preg_match('/\\A[0-9a-fA-F]{24}\\z/', $id)) {
                throw new \InvalidArgumentException('Error reading ObjectId string: '.$id);
            }
            $hex = strtolower($id);
        }
        self::$objectIds[$entry->id] = $hex;
        $entry->constructed = true;
    }

    public static function objectIdHex(ObjectEntry $entry): string
    {
        return self::$objectIds[$entry->id] ?? '';
    }

    public static function objectIdTimestamp(ObjectEntry $entry): int
    {
        $hex = self::objectIdHex($entry);
        if (24 !== strlen($hex)) {
            return 0;
        }

        return (int) hexdec(substr($hex, 0, 8));
    }

    public static function initUtcDateTime(ObjectEntry $entry, string $milliseconds): void
    {
        self::$utcDateTimes[$entry->id] = $milliseconds;
        $entry->constructed = true;
    }

    public static function utcDateTimeMs(ObjectEntry $entry): string
    {
        return self::$utcDateTimes[$entry->id] ?? '0';
    }

    public static function initBinary(ObjectEntry $entry, string $data, int $type): void
    {
        self::$binaries[$entry->id] = ['data' => $data, 'type' => $type];
        $entry->constructed = true;
    }

    /** @return array{data: string, type: int} */
    public static function binaryState(ObjectEntry $entry): array
    {
        return self::$binaries[$entry->id] ?? ['data' => '', 'type' => 0];
    }

    public static function initRegex(ObjectEntry $entry, string $pattern, string $flags): void
    {
        self::$regexes[$entry->id] = ['pattern' => $pattern, 'flags' => $flags];
        $entry->constructed = true;
    }

    /** @return array{pattern: string, flags: string} */
    public static function regexState(ObjectEntry $entry): array
    {
        return self::$regexes[$entry->id] ?? ['pattern' => '', 'flags' => ''];
    }

    public static function initDecimal128(ObjectEntry $entry, string $value): void
    {
        self::$decimal128[$entry->id] = $value;
        $entry->constructed = true;
    }

    public static function decimal128Value(ObjectEntry $entry): string
    {
        return self::$decimal128[$entry->id] ?? '0';
    }

    public static function initTimestamp(ObjectEntry $entry, int $increment, int $timestamp): void
    {
        self::$timestamps[$entry->id] = [
            'increment' => $increment & 0xffffffff,
            'timestamp' => $timestamp & 0xffffffff,
        ];
        $entry->constructed = true;
    }

    /** @return array{increment: int, timestamp: int} */
    public static function timestampState(ObjectEntry $entry): array
    {
        return self::$timestamps[$entry->id] ?? ['increment' => 0, 'timestamp' => 0];
    }

    public static function initCommand(ObjectEntry $entry): void
    {
        self::$commands[$entry->id] = true;
        $entry->constructed = true;
    }

    public static function initReadPreference(ObjectEntry $entry, int $mode, string $modeString): void
    {
        self::$readPreferences[$entry->id] = ['mode' => $mode, 'modeString' => $modeString];
        $entry->constructed = true;
    }

    /** @return array{mode: int, modeString: string} */
    public static function readPreferenceState(ObjectEntry $entry): array
    {
        return self::$readPreferences[$entry->id] ?? ['mode' => 1, 'modeString' => 'primary'];
    }

    /** @param int|string $w */
    public static function initWriteConcern(ObjectEntry $entry, $w, int $wtimeout, ?bool $journal): void
    {
        self::$writeConcerns[$entry->id] = ['w' => $w, 'wtimeout' => $wtimeout, 'journal' => $journal];
        $entry->constructed = true;
    }

    /** @return array{w: int|string, wtimeout: int, journal: ?bool} */
    public static function writeConcernState(ObjectEntry $entry): array
    {
        return self::$writeConcerns[$entry->id] ?? ['w' => 1, 'wtimeout' => 0, 'journal' => null];
    }

    public static function coerceOptionalObjectId(Variable $var, string $label): ?string
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_NULL === $var->type) {
            return null;
        }

        return VmString::coerceStringBuiltinArg($var, $label, 0, 'id');
    }

    public static function resolveReadPreferenceMode(Variable $var): array
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_INTEGER === $var->type) {
            $mode = $var->toInt();
            $map = [
                1 => 'primary',
                5 => 'primaryPreferred',
                2 => 'secondary',
                6 => 'secondaryPreferred',
                10 => 'nearest',
            ];
            if (!isset($map[$mode])) {
                throw new \InvalidArgumentException(
                    'Expected ReadPreference mode to be primary, primaryPreferred, secondary, secondaryPreferred, or nearest'
                );
            }

            return [$mode, $map[$mode]];
        }
        if (Variable::TYPE_STRING === $var->type) {
            $modeString = $var->toString();
            $map = [
                'primary' => 1,
                'primaryPreferred' => 5,
                'secondary' => 2,
                'secondaryPreferred' => 6,
                'nearest' => 10,
            ];
            if (!isset($map[$modeString])) {
                throw new \InvalidArgumentException(
                    'Expected ReadPreference mode to be primary, primaryPreferred, secondary, secondaryPreferred, or nearest'
                );
            }

            return [$map[$modeString], $modeString];
        }
        throw new \TypeError(\sprintf(
            'MongoDB\\Driver\\ReadPreference::__construct(): Argument #1 ($mode) must be of type string|int, %s given',
            VmMongodb::typeLabel($var)
        ));
    }

    /** @return int|string */
    public static function resolveWriteConcernW(Variable $var)
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_INTEGER === $var->type) {
            return $var->toInt();
        }
        if (Variable::TYPE_STRING === $var->type) {
            return $var->toString();
        }
        throw new \TypeError(\sprintf(
            'MongoDB\\Driver\\WriteConcern::__construct(): Argument #1 ($w) must be of type string|int, %s given',
            VmMongodb::typeLabel($var)
        ));
    }

    public static function utcMillisecondsFromArg(?Variable $var): string
    {
        if (null === $var) {
            return (string) (int) floor(microtime(true) * 1000);
        }
        $var = $var->resolveIndirect();
        if (Variable::TYPE_NULL === $var->type) {
            return (string) (int) floor(microtime(true) * 1000);
        }
        if (Variable::TYPE_INTEGER === $var->type) {
            return (string) $var->toInt();
        }
        if (Variable::TYPE_FLOAT === $var->type) {
            return (string) (int) $var->toFloat();
        }
        if (Variable::TYPE_STRING === $var->type) {
            $s = $var->toString();
            if (!preg_match('/\\A-?\\d+\\z/', $s)) {
                throw new \InvalidArgumentException(
                    'Error reading "milliseconds" unsigned 64-bit integer string'
                );
            }

            return $s;
        }
        throw new \TypeError(\sprintf(
            'MongoDB\\BSON\\UTCDateTime::__construct(): Argument #1 ($milliseconds) must be of type string|int|float|null, %s given',
            VmMongodb::typeLabel($var)
        ));
    }

    /**
     * @return mixed
     */
    public static function fromJson(string $json)
    {
        $decoded = json_decode($json);
        if (JSON_ERROR_NONE !== json_last_error()) {
            throw new \UnexpectedValueException('Failed to parse JSON: '.json_last_error_msg());
        }

        return $decoded;
    }

    /**
     * @param mixed $value
     */
    public static function toJson($value): string
    {
        $json = json_encode($value);
        if (false === $json) {
            throw new \UnexpectedValueException('Failed to encode JSON: '.json_last_error_msg());
        }

        return $json;
    }

    private static function generateObjectIdHex(): string
    {
        $time = pack('N', time());
        static $random = null;
        if (null === $random) {
            $random = random_bytes(5);
            self::$objectIdCounter = random_int(0, 0xffffff);
        }
        self::$objectIdCounter = (self::$objectIdCounter + 1) & 0xffffff;
        $counter = substr(pack('N', self::$objectIdCounter), 1);

        return bin2hex($time . $random . $counter);
    }
}
