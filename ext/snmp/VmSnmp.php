<?php

declare(strict_types=1);

namespace PHPCompiler\ext\snmp;

use PHPCfg\Func as CfgFunc;
use PHPCompiler\Frame;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;
use PHPCompiler\ext\standard\VmMath;
use PHPCompiler\ext\standard\VmString;

/**
 * SNMP VM class + shared snmpget/walk/set/getnext/realwalk semantics (php-src ext/snmp; #6070, #22244).
 *
 * v1 stubs: no Net-SNMP FFI yet — queries warn and return false like an unreachable agent.
 */
final class VmSnmp
{
    public const CLASS_LC = 'snmp';

    /** @var array<int, SnmpState> */
    private static array $store = [];

    private static bool $quickPrint = false;
    private static bool $enumPrint = false;
    private static int $oidOutputFormat = SnmpConstants::OID_OUTPUT_MODULE;
    private static int $valueRetrieval = SnmpConstants::VALUE_LIBRARY;

    public static function registerClass(Context $ctx): void
    {
        if (isset($ctx->classes[self::CLASS_LC]) && isset($ctx->classes[self::CLASS_LC]->methods['setsecurity'])) {
            return;
        }

        $entry = isset($ctx->classes[self::CLASS_LC])
            ? $ctx->classes[self::CLASS_LC]
            : new ClassEntry('SNMP');
        $entry->isInternal = true;
        foreach (SnmpConstants::CLASS_CONSTANTS as $name => $value) {
            $const = new Variable(Variable::TYPE_INTEGER);
            $const->int($value);
            $entry->constants[$name] = $const;
            $entry->constNames[$name] = SnmpConstants::CLASS_CONSTANT_NAMES[$name];
        }

        $pub = CfgFunc::FLAG_PUBLIC;
        $entry->constructor = new SNMPConstruct();
        $entry->methods['__construct'] = $entry->constructor;
        $entry->methodVisibility['__construct'] = $pub;

        $methods = [
            'get' => new SNMPGetMethod(),
            'getnext' => new SNMPGetNextMethod(),
            'walk' => new SNMPWalkMethod(),
            'set' => new SNMPSetMethod(),
            'setsecurity' => new SNMPSetSecurity(),
            'close' => new SNMPClose(),
            'geterror' => new SNMPGetError(),
            'geterrno' => new SNMPGetErrno(),
        ];
        foreach ($methods as $name => $method) {
            $entry->methods[$name] = $method;
            $entry->methodVisibility[$name] = $pub;
            $entry->methodNames[$name] = match ($name) {
                'geterror' => 'getError',
                'geterrno' => 'getErrno',
                'setsecurity' => 'setSecurity',
                default => $name,
            };
        }

        $ctx->classes[self::CLASS_LC] = $entry;
    }

    public static function initObject(
        ObjectEntry $entry,
        int $version,
        string $hostname,
        string $community,
        int $timeout,
        int $retries
    ): void {
        $state = new SnmpState();
        $state->version = $version;
        $state->hostname = $hostname;
        $state->community = $community;
        $state->timeout = $timeout;
        $state->retries = $retries;
        $state->closed = false;
        $state->errno = SnmpConstants::ERRNO_NOERROR;
        $state->error = '';
        self::$store[$entry->id] = $state;
        $entry->constructed = true;
    }

    public static function state(ObjectEntry $entry): SnmpState
    {
        if (!isset(self::$store[$entry->id])) {
            throw new \LogicException('SNMP object has no internal state');
        }

        return self::$store[$entry->id];
    }

    public static function requireReceiver(Variable $var, string $label): ObjectEntry
    {
        $resolved = $var->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $resolved->type) {
            throw new \TypeError($label.' must be called on an object');
        }
        $obj = $resolved->toObject();
        if (self::CLASS_LC !== strtolower($obj->class->name)) {
            throw new \TypeError($label.' must be called on SNMP');
        }

        return $obj;
    }

    public static function proceduralGet(
        Context $ctx,
        ?Frame $frame,
        string $function,
        string $hostname,
        string $community,
        string $objectId
    ): false {
        self::warnUnavailable($ctx, $frame, $function, $hostname, $objectId);

        return false;
    }

    public static function proceduralWalk(
        Context $ctx,
        ?Frame $frame,
        string $function,
        string $hostname,
        string $community,
        string $objectId
    ): false {
        self::warnUnavailable($ctx, $frame, $function, $hostname, $objectId);

        return false;
    }

    public static function proceduralGetNext(
        Context $ctx,
        ?Frame $frame,
        string $function,
        string $hostname,
        string $community,
        string $objectId
    ): false {
        self::warnUnavailable($ctx, $frame, $function, $hostname, $objectId);

        return false;
    }

    public static function proceduralRealWalk(
        Context $ctx,
        ?Frame $frame,
        string $function,
        string $hostname,
        string $community,
        string $objectId
    ): false {
        self::warnUnavailable($ctx, $frame, $function, $hostname, $objectId);

        return false;
    }

    public static function proceduralSet(
        Context $ctx,
        ?Frame $frame,
        string $function,
        string $hostname,
        string $community,
        string $objectId
    ): false {
        self::warnUnavailable($ctx, $frame, $function, $hostname, $objectId);

        return false;
    }

    public static function instanceGet(ObjectEntry $receiver, Context $ctx, ?Frame $frame, string $objectId): false
    {
        $state = self::state($receiver);
        if ($state->closed) {
            $state->errno = SnmpConstants::ERRNO_GENERIC;
            $state->error = 'SNMP session is closed';
            self::warn($ctx, $frame, 'SNMP::get(): SNMP session is closed');

            return false;
        }
        $state->errno = SnmpConstants::ERRNO_GENERIC;
        $state->error = 'No response from '.$state->hostname;
        self::warnUnavailable($ctx, $frame, 'SNMP::get', $state->hostname, $objectId);

        return false;
    }

    public static function instanceWalk(ObjectEntry $receiver, Context $ctx, ?Frame $frame, string $objectId): false
    {
        $state = self::state($receiver);
        if ($state->closed) {
            $state->errno = SnmpConstants::ERRNO_GENERIC;
            $state->error = 'SNMP session is closed';
            self::warn($ctx, $frame, 'SNMP::walk(): SNMP session is closed');

            return false;
        }
        $state->errno = SnmpConstants::ERRNO_GENERIC;
        $state->error = 'No response from '.$state->hostname;
        self::warnUnavailable($ctx, $frame, 'SNMP::walk', $state->hostname, $objectId);

        return false;
    }

    public static function instanceGetNext(ObjectEntry $receiver, Context $ctx, ?Frame $frame, string $objectId): false
    {
        $state = self::state($receiver);
        if ($state->closed) {
            $state->errno = SnmpConstants::ERRNO_GENERIC;
            $state->error = 'SNMP session is closed';
            self::warn($ctx, $frame, 'SNMP::getnext(): SNMP session is closed');

            return false;
        }
        $state->errno = SnmpConstants::ERRNO_GENERIC;
        $state->error = 'No response from '.$state->hostname;
        self::warnUnavailable($ctx, $frame, 'SNMP::getnext', $state->hostname, $objectId);

        return false;
    }

    public static function instanceSet(ObjectEntry $receiver, Context $ctx, ?Frame $frame, string $objectId): false
    {
        $state = self::state($receiver);
        if ($state->closed) {
            $state->errno = SnmpConstants::ERRNO_GENERIC;
            $state->error = 'SNMP session is closed';
            self::warn($ctx, $frame, 'SNMP::set(): SNMP session is closed');

            return false;
        }
        $state->errno = SnmpConstants::ERRNO_GENERIC;
        $state->error = 'No response from '.$state->hostname;
        self::warnUnavailable($ctx, $frame, 'SNMP::set', $state->hostname, $objectId);

        return false;
    }

    public static function close(ObjectEntry $receiver): bool
    {
        $state = self::state($receiver);
        if ($state->closed) {
            return true;
        }
        $state->closed = true;
        $state->errno = SnmpConstants::ERRNO_NOERROR;
        $state->error = '';

        return true;
    }

    public static function setSecurity(
        ObjectEntry $receiver,
        string $securityLevel,
        string $authProtocol,
        string $authPassphrase,
        string $privacyProtocol,
        string $privacyPassphrase,
        string $contextName,
        string $contextEngineId
    ): bool {
        $state = self::state($receiver);
        if ($state->closed) {
            $state->errno = SnmpConstants::ERRNO_GENERIC;
            $state->error = 'SNMP session is closed';

            return false;
        }
        $state->securityLevel = $securityLevel;
        $state->authProtocol = $authProtocol;
        $state->authPassphrase = $authPassphrase;
        $state->privacyProtocol = $privacyProtocol;
        $state->privacyPassphrase = $privacyPassphrase;
        $state->contextName = $contextName;
        $state->contextEngineId = $contextEngineId;
        $state->errno = SnmpConstants::ERRNO_NOERROR;
        $state->error = '';

        return true;
    }

    public static function getQuickPrint(): bool
    {
        return self::$quickPrint;
    }

    public static function setQuickPrint(bool $enable): bool
    {
        self::$quickPrint = $enable;

        return true;
    }

    public static function setEnumPrint(bool $enable): bool
    {
        self::$enumPrint = $enable;

        return true;
    }

    public static function setOidOutputFormat(int $format): bool
    {
        self::$oidOutputFormat = $format;

        return true;
    }

    public static function setValueRetrieval(int $method): bool
    {
        self::$valueRetrieval = $method;

        return true;
    }

    public static function getValueRetrieval(): int
    {
        return self::$valueRetrieval;
    }

    public static function readMib(Context $ctx, ?Frame $frame, string $filename): bool
    {
        if ('' === $filename || (!is_file($filename) && !is_readable($filename))) {
            self::warn($ctx, $frame, 'snmp_read_mib(): Unable to read MIB file: '.$filename);

            return false;
        }
        // No Net-SNMP MIB loader — accept readable path like a successful stub load.
        return true;
    }

    public static function coerceBoolArg(Variable $var, string $label, int $index, string $paramName): bool
    {
        $resolved = $var->resolveIndirect();
        if (Variable::TYPE_BOOLEAN === $resolved->type) {
            return $resolved->toBool();
        }
        if (Variable::TYPE_INTEGER === $resolved->type) {
            return 0 !== $resolved->toInt();
        }
        if (Variable::TYPE_NULL === $resolved->type) {
            return false;
        }

        return '' !== VmString::coerceStringBuiltinArg($resolved, $label, $index, $paramName);
    }

    public static function coerceObjectId(Variable $var, string $function, int $argIndex): string
    {
        $resolved = $var->resolveIndirect();
        if (Variable::TYPE_ARRAY === $resolved->type) {
            $parts = [];
            foreach ($resolved->toArray()->iterate(true) as $partVar) {
                $part = $partVar->resolveIndirect();
                if (Variable::TYPE_INTEGER === $part->type) {
                    $parts[] = (string) $part->toInt();
                } elseif (Variable::TYPE_FLOAT === $part->type) {
                    $parts[] = (string) (int) $part->toFloat();
                } else {
                    $parts[] = VmString::coerceStringBuiltinArg($part, $function, $argIndex, 'object_id');
                }
            }

            return implode('.', $parts);
        }

        return VmString::coerceStringBuiltinArg($resolved, $function, $argIndex, 'object_id');
    }

    /**
     * Coerce snmpset type/value (php-src: array|string) for arity validation.
     * Arrays are accepted without joining — unreachable-agent stubs only need type acceptance.
     *
     * @return string|list<string>
     */
    public static function coerceTypeOrValue(Variable $var, string $function, int $argIndex, string $paramName): string|array
    {
        $resolved = $var->resolveIndirect();
        if (Variable::TYPE_ARRAY === $resolved->type) {
            $parts = [];
            foreach ($resolved->toArray()->iterate(true) as $partVar) {
                $parts[] = VmString::coerceStringBuiltinArg($partVar, $function, $argIndex, $paramName);
            }

            return $parts;
        }

        return VmString::coerceStringBuiltinArg($resolved, $function, $argIndex, $paramName);
    }

    public static function coerceStringArg(Variable $var, string $label, int $index, string $paramName): string
    {
        return VmString::coerceStringBuiltinArg($var, $label, $index, $paramName);
    }

    public static function coerceIntArg(Variable $var, string $label, int $index, string $paramName): int
    {
        return VmMath::parseIntBuiltinArg($var, $label, $index, $paramName);
    }

    private static function warnUnavailable(
        Context $ctx,
        ?Frame $frame,
        string $function,
        string $hostname,
        string $objectId
    ): void {
        self::warn(
            $ctx,
            $frame,
            sprintf('%s(): No response from %s for %s', $function, $hostname, $objectId)
        );
    }

    private static function warn(Context $ctx, ?Frame $frame, string $message): void
    {
        $ctx->errors->triggerError(
            $message,
            ErrorReporter::E_WARNING,
            null,
            $ctx,
            $frame
        );
    }
}