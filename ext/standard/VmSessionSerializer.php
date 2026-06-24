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
            if (Variable::TYPE_STRING !== $keyVar->type) {
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
        foreach ($session->iterateKeyed(true) as [$keyVar, $valueVar]) {
            $keyVar = $keyVar->resolveIndirect();
            if (Variable::TYPE_STRING !== $keyVar->type) {
                continue;
            }
            $key = $keyVar->toString();
            if (str_contains($key, '|')) {
                return false;
            }
            $serialized = SerializeJitHelper::serializeSessionWireValue($valueVar);
            if (null === $serialized) {
                return false;
            }
            $out .= $key.'|'.$serialized;
        }

        return $out;
    }

    public static function decodePhp(Context $ctx, string $payload): bool
    {
        $sessionVar = $ctx->ensureSuperglobal('_SESSION');
        $ht = Variable::TYPE_ARRAY === $sessionVar->type
            ? $sessionVar->toArray()
            : new HashTable();

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
            $ht->add($key, $slot);
            $pos += $span;
        }
        $sessionVar->array($ht);

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
