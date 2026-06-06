<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\EnumCaseEntry;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\InterfaceCheck;
use PHPCompiler\VM\Variable;

/**
 * Export/import VM values for json_encode() / json_decode() delegation.
 *
 * Tracks {@see lastError()} / {@see lastErrorMsg()} for VM parity with Zend ext/json (issue #3175).
 */
final class VmJson
{
    /** JSON_ERROR_UNSUPPORTED_TYPE — object without JsonSerializable (Zend ext/json). */
    public const ERROR_UNSUPPORTED_TYPE = 8;

    /** Last JSON_ERROR_* from VM json_* (Zend ext/json/php_json.c). */
    private static int $lastError = 0;

    public static function lastError(): int
    {
        return self::$lastError;
    }

    public static function setLastError(int $code): void
    {
        self::$lastError = $code;
    }

    public static function lastErrorMsg(): string
    {
        return self::errorMsgForCode(self::$lastError);
    }

    public static function errorMsgForCode(int $code): string
    {
        return match ($code) {
            0 => 'No error',
            1 => 'Maximum stack depth exceeded',
            4 => 'Syntax error',
            self::ERROR_UNSUPPORTED_TYPE => 'Type is not supported',
            default => 'Unknown error',
        };
    }

    public static function syncLastErrorFromHost(): void
    {
        self::$lastError = \json_last_error();
    }

    public static function import(mixed $value): Variable
    {
        $var = new Variable();
        if (null === $value) {
            $var->null();

            return $var;
        }
        if (\is_bool($value)) {
            $var->bool($value);

            return $var;
        }
        if (\is_int($value)) {
            $var->int($value);

            return $var;
        }
        if (\is_float($value)) {
            $var->float($value);

            return $var;
        }
        if (\is_string($value)) {
            $var->string($value);

            return $var;
        }
        if (!\is_array($value)) {
            throw new \LogicException(
                'json_decode() result type not supported in this compiler build'
            );
        }
        $ht = new \PHPCompiler\VM\HashTable();
        $isList = array_is_list($value);
        foreach ($value as $key => $item) {
            $slot = self::import($item);
            if ($isList) {
                $ht->addIndex((int) $key, $slot);
            } else {
                if (!\is_string($key) && !\is_int($key)) {
                    throw new \LogicException(
                        'json_decode() only supports string keys in this compiler build'
                    );
                }
                $ht->add((string) $key, $slot);
            }
        }
        $var->array($ht);

        return $var;
    }

    public static function export(Variable $v, ?Context $ctx = null, ?VM $vm = null): mixed
    {
        $v = $v->resolveIndirect();
        switch ($v->type) {
            case Variable::TYPE_NULL:
                return null;
            case Variable::TYPE_INTEGER:
                return $v->toInt();
            case Variable::TYPE_FLOAT:
                return $v->toFloat();
            case Variable::TYPE_BOOLEAN:
                return $v->toBool();
            case Variable::TYPE_STRING:
                return $v->toString();
            case Variable::TYPE_ARRAY:
                $out = [];
                foreach ($v->toArray()->iterateKeyed(true) as [$key, $value]) {
                    $k = $key->resolveIndirect();
                    if (Variable::TYPE_STRING !== $k->type) {
                        throw new \LogicException(
                            'json_encode() only supports string keys in this compiler build'
                        );
                    }
                    $out[$k->toString()] = self::export($value, $ctx, $vm);
                }

                return $out;
            case Variable::TYPE_ENUM_CASE:
                return self::exportEnumCase($v->toEnumCase());
            case Variable::TYPE_OBJECT:
                if (null === $ctx || null === $vm) {
                    throw new \LogicException(
                        'json_encode() value type not supported in this compiler build'
                    );
                }
                $object = $v->toObject();
                if (EnumCaseSupport::isEnumCase($object)) {
                    $backing = new Variable();
                    if (null !== $object->enumCaseValue) {
                        $backing->copyFrom($object->enumCaseValue);
                    } else {
                        $backing->string('');
                    }

                    return self::exportEnumCase(new EnumCaseEntry(
                        $object->class,
                        $object->enumCaseName ?? '',
                        $backing
                    ));
                }
                if (!InterfaceCheck::entryImplements($object->class, 'jsonserializable', $ctx)) {
                    self::$lastError = self::ERROR_UNSUPPORTED_TYPE;

                    throw new VmJsonExportException(self::ERROR_UNSUPPORTED_TYPE);
                }
                if (!$vm->hasInstanceMethod($object->class, 'jsonserialize')) {
                    throw new \Error(
                        'Call to undefined method '.$object->class->name.'::jsonSerialize()'
                    );
                }
                $serialized = $vm->invokeInstanceMethod($object, 'jsonSerialize')->resolveIndirect();

                return self::export($serialized, $ctx, $vm);
            default:
                throw new \LogicException(
                    'json_encode() value type not supported in this compiler build'
                );
        }
    }

    /**
     * Zend ext/json/php_json.c — backed enum cases encode as backing scalar; unit cases as "".
     */
    private static function exportEnumCase(EnumCaseEntry $case): mixed
    {
        if (null === $case->enumClass->backedType) {
            return '';
        }
        $backing = $case->backingValue->resolveIndirect();

        return match ($case->enumClass->backedType) {
            'int' => $backing->toInt(),
            'string' => $backing->toString(),
            default => throw new \LogicException(
                'json_encode() unsupported enum backing type in this compiler build'
            ),
        };
    }
}

/** json_encode() export failure with a JSON_ERROR_* code (issue #3370). */
final class VmJsonExportException extends \RuntimeException
{
    public function __construct(public readonly int $errorCode)
    {
        parent::__construct(VmJson::errorMsgForCode($errorCode));
    }
}
