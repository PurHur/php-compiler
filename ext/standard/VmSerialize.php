<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\Variable;

/**
 * Export/import VM values for serialize() / unserialize() (issues #1174–#1175).
 */
final class VmSerialize
{
    public static function serialize(Variable $v): string
    {
        $exported = self::export($v->resolveIndirect());
        $encoded = \serialize($exported);
        if (false === $encoded) {
            throw new \LogicException('serialize() failed in this compiler build');
        }

        return $encoded;
    }

  /**
     * @return array<string, mixed>|list<mixed>
     */
    public static function unserialize(string $payload): array
    {
        $decoded = @\unserialize($payload, ['allowed_classes' => false]);
        if (!\is_array($decoded)) {
            throw new \LogicException('unserialize() only supports arrays in this compiler build');
        }

        return $decoded;
    }

    public static function importArray(array $data): Variable
    {
        return VmJson::import($data);
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
                $ht = $v->toArray();
                $out = [];
                $isList = true;
                $expected = 0;
                foreach ($ht->iterateKeyed(true) as [$key, $value]) {
                    $k = $key->resolveIndirect();
                    if (Variable::TYPE_INTEGER === $k->type && $k->toInt() === $expected) {
                        $out[] = self::export($value);
                        ++$expected;
                    } else {
                        $isList = false;
                        break;
                    }
                }
                if ($isList && 0 === $expected) {
                    return [];
                }
                if ($isList) {
                    return $out;
                }
                $assoc = [];
                foreach ($ht->iterateKeyed(true) as [$key, $value]) {
                    $k = $key->resolveIndirect();
                    if (Variable::TYPE_STRING !== $k->type && Variable::TYPE_INTEGER !== $k->type) {
                        throw new \LogicException(
                            'serialize() only supports string or integer keys in this compiler build'
                        );
                    }
                    $key = Variable::TYPE_INTEGER === $k->type ? (string) $k->toInt() : $k->toString();
                    $assoc[$key] = self::export($value);
                }

                return $assoc;
            default:
                throw new \LogicException(
                    'serialize() value type not supported in this compiler build'
                );
        }
    }
}
