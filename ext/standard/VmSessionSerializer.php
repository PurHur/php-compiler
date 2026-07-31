<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\Context;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * session_encode() / session_decode() php serialize handler (php-src ext/session/mod_php.c).
 *
 * Wire format: {@code key|serialized_value} pairs concatenated (default session.serialize_handler).
 */
final class VmSessionSerializer
{
    /**
     * @return string|false
     */
    public static function encodePhp(Context $ctx, HashTable $session)
    {
        $out = '';
        foreach ($session->iterateKeyed(true) as [$keyVar, $valueVar]) {
            $keyVar = $keyVar->resolveIndirect();
            if (Variable::TYPE_STRING !== ($keyVar->type & 0x7f)) {
                continue;
            }
            $key = $keyVar->toString();
            if (str_contains($key, '|')) {
                return false;
            }
            $out .= $key.'|'.VmSerialize::serializeValue($ctx, $valueVar);
        }

        return $out;
    }

    /**
     * JIT wire encode without VM Context — scalars/arrays via {@see SerializeJitHelper}.
     *
     * @return string|false
     */
    public static function encodeWireHashTable(HashTable $session): string|false
    {
        $out = '';
        // NestedJIT lowers exportKeyValuePairs, not iterateKeyed (#12908 / #21900).
        foreach ($session->exportKeyValuePairs(true) as [$keyVar, $valueVar]) {
            $keyVar = $keyVar->resolveIndirect();
            if (Variable::TYPE_STRING !== ($keyVar->type & 0x7f)) {
                continue;
            }
            $key = $keyVar->toString();
            if (str_contains($key, '|')) {
                return false;
            }
            $serialized = self::serializeSessionWireValue($valueVar);
            if (null === $serialized) {
                return false;
            }
            $out .= $key.'|'.$serialized;
        }

        return $out;
    }

    /**
     * JIT session wire encode for one value — NestedJIT-safe scalar path (#21900).
     *
     * Avoids {@see VmSerializeFormat}/{@see VmJson} which throw under NestedJIT and
     * made {@see encodeWireHashTable} return false (no session file written).
     */
    public static function serializeSessionWireValue(Variable $value): ?string
    {
        $value = $value->resolveIndirect();
        // Mask IS_REFCOUNTED — NestedJIT value-box type bytes may be JIT tags (4|0x80) (#21921).
        switch ($value->type & 0x7f) {
            case Variable::TYPE_NULL:
                return 'N;';
            case Variable::TYPE_BOOLEAN:
                return $value->toBool() ? 'b:1;' : 'b:0;';
            case Variable::TYPE_INTEGER:
                return 'i:'.$value->toInt().';';
            case Variable::TYPE_FLOAT:
                return 'd:'.$value->toFloat().';';
            case Variable::TYPE_STRING:
                $s = $value->toString();

                return 's:'.\strlen($s).':"'.$s.'";';
            case Variable::TYPE_OBJECT:
                return null;
            case Variable::TYPE_ARRAY:
                try {
                    return VmSerializeFormat::encodeExported(self::exportJitSessionValue($value));
                } catch (\Throwable) {
                    return null;
                }
            default:
                return null;
        }
    }

    /**
     * @return array<mixed>|bool|float|int|null|string
     */
    private static function exportJitSessionValue(Variable $value): mixed
    {
        $value = $value->resolveIndirect();
        if (Variable::TYPE_ARRAY === $value->type) {
            $out = [];
            // NestedJIT-safe: exportKeyValuePairs, not iterateKeyed (#12908 / #20773).
            foreach ($value->toArray()->exportKeyValuePairs(true) as [$key, $elem]) {
                $k = $key->resolveIndirect();
                if (Variable::TYPE_STRING === $k->type) {
                    $out[$k->toString()] = self::exportJitSessionValue($elem);
                } elseif (Variable::TYPE_INTEGER === $k->type) {
                    $out[$k->toInt()] = self::exportJitSessionValue($elem);
                } else {
                    throw new \LogicException(
                        'serialize() only supports string or integer keys in this compiler build'
                    );
                }
            }

            return $out;
        }

        return VmJson::export($value);
    }

    /**
     * Decode php-handler wire into $_SESSION by merging keys (php-src mod_php.c).
     *
     * Does not call track_init — existing keys are preserved unless overwritten (#26088).
     * Callers that hydrate from storage ({@see VmSession::loadSession}) must clear first.
     */
    public static function decodePhp(Context $ctx, string $payload): bool
    {
        $incoming = new HashTable();

        $pos = 0;
        $len = \strlen($payload);
        while ($pos < $len) {
            $pipe = strpos($payload, '|', $pos);
            if (false === $pipe) {
                return false;
            }
            $key = \substr($payload, $pos, $pipe - $pos);
            if ('' === $key) {
                return false;
            }
            $pos = $pipe + 1;
            $span = self::serializedValueByteLength($payload, $pos);
            if (null === $span) {
                return false;
            }
            $fragment = \substr($payload, $pos, $span);
            $decoded = VmSerialize::unserializePayload($ctx, $fragment);
            if (false === $decoded && 'b:0;' !== $fragment) {
                return false;
            }
            $slot = new Variable();
            if ($decoded instanceof Variable) {
                $slot->copyFrom($decoded);
            } else {
                $slot->copyFrom(VmJson::import($decoded));
            }
            $incoming->add($key, $slot);
            $pos += $span;
        }
        $sessionVar = $ctx->ensureSuperglobal('_SESSION');
        if (Variable::TYPE_ARRAY !== ($sessionVar->type & 0x7f)) {
            $sessionVar->array(new HashTable());
        } else {
            $sessionVar->separateArrayForWrite();
        }
        $sessionVar->toArray()->mergeStringKeysFrom($incoming, true);

        return true;
    }

    public static function decodeWireHashTable(string $payload): ?HashTable
    {
        $ht = new HashTable();
        $pos = 0;
        $len = \strlen($payload);
        while ($pos < $len) {
            $pipe = strpos($payload, '|', $pos);
            if (false === $pipe) {
                return null;
            }
            $key = \substr($payload, $pos, $pipe - $pos);
            if ('' === $key) {
                return null;
            }
            $pos = $pipe + 1;
            $span = self::serializedValueByteLength($payload, $pos);
            if (null === $span) {
                return null;
            }
            $fragment = \substr($payload, $pos, $span);
            $decoded = VmUnserializeFormat::decodePayload($fragment);
            if (false === $decoded && 'b:0;' !== $fragment) {
                return null;
            }
            $slot = new Variable();
            if ($decoded instanceof Variable) {
                $slot->copyFrom($decoded);
            } else {
                $slot->copyFrom(VmJson::import($decoded));
            }
            $ht->add($key, $slot);
            $pos += $span;
        }

        return $ht;
    }

    private static function serializedValueByteLength(string $payload, int $offset): ?int
    {
        $len = \strlen($payload);
        if ($offset >= $len) {
            return null;
        }
        $type = $payload[$offset];
        if ('N' === $type) {
            return ($offset + 1 < $len && ';' === $payload[$offset + 1]) ? 2 : null;
        }
        if ('b' === $type || 'i' === $type || 'd' === $type) {
            $semi = strpos($payload, ';', $offset);
            if (false === $semi) {
                return null;
            }

            return $semi - $offset + 1;
        }
        if ('s' === $type) {
            if (!\preg_match('/\Gs:(\d+):"/', $payload, $m, 0, $offset)) {
                return null;
            }
            $strLen = (int) $m[1];
            $dataStart = $offset + \strlen($m[0]);

            return $dataStart + $strLen + 2 - $offset;
        }
        if ('E' === $type) {
            if (!\preg_match('/\GE:(\d+):"/', $payload, $m, 0, $offset)) {
                return null;
            }
            $innerLen = (int) $m[1];
            $dataStart = $offset + \strlen($m[0]);

            return $dataStart + $innerLen + 2 - $offset;
        }
        if ('a' === $type || 'O' === $type || 'C' === $type) {
            return self::scanBraceTerminatedLength($payload, $offset);
        }

        return null;
    }

    private static function scanBraceTerminatedLength(string $payload, int $offset): ?int
    {
        $open = strpos($payload, '{', $offset);
        if (false === $open) {
            return null;
        }
        $depth = 0;
        $inString = false;
        $escaped = false;
        $n = \strlen($payload);
        for ($i = $open; $i < $n; ++$i) {
            $ch = $payload[$i];
            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                    continue;
                }
                if ('\\' === $ch) {
                    $escaped = true;
                    continue;
                }
                if ('"' === $ch) {
                    $inString = false;
                }
                continue;
            }
            if ('"' === $ch) {
                $inString = true;
                continue;
            }
            if ('{' === $ch) {
                ++$depth;
                continue;
            }
            if ('}' === $ch) {
                --$depth;
                if (0 === $depth) {
                    return $i - $offset + 1;
                }
            }
        }

        return null;
    }
}
