<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\Variable;

/**
 * Export/import VM values for json_encode() / json_decode() delegation.
 *
 * Tracks {@see lastError()} / {@see lastErrorMsg()} for VM parity with Zend ext/json (issue #3175).
 */
final class VmJson
{
    /** Last JSON_ERROR_* from VM json_* (Zend ext/json/php_json.c). */
    private static int $lastError = 0;

    public static function lastError(): int
    {
        return self::$lastError;
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

    public static function export(Variable $v): mixed
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
                    $out[$k->toString()] = self::export($value);
                }

                return $out;
            default:
                throw new \LogicException(
                    'json_encode() value type not supported in this compiler build'
                );
        }
    }
}
