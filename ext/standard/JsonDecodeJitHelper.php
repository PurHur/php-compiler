<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * json_decode() NestedJIT int-wire helper (#9359, #20829).
 *
 * Peer {@see UnserializeJitHelper::decode}. Object/array NestedJIT is follow-up.
 * php-src: ext/json/php_json.c — php_json_decode_ex
 */
final class JsonDecodeJitHelper
{
    /**
     * JSON integer digit walk (#20829). Non-int payloads return 0.
     */
    public static function decode(string $payload): int
    {
        $len = \strlen($payload);
        if ($len < 1) {
            return 0;
        }
        $i = 0;
        if ('-' === $payload[0] || '+' === $payload[0]) {
            $i = 1;
        }
        if ($i >= $len) {
            return 0;
        }
        for (; $i < $len; ++$i) {
            $c = $payload[$i];
            if ($c < '0' || $c > '9') {
                return 0;
            }
        }

        return (int) $payload;
    }
}
