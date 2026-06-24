<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * SSOT facade for call-time argument unpack + named-parameter merge (#10202).
 *
 * php-src: Zend/zend_execute.c — ZEND_SEND_UNPACK, zend_unpack_args()
 */
final class CallUnpackSupport
{
    public const NON_ARRAY_MESSAGE = CallUnpack::NON_ARRAY_MESSAGE;

    public const STRING_KEYS_MESSAGE = CallUnpack::STRING_KEYS_MESSAGE;

    /**
     * @param list<string> $paramNames
     *
     * @return list<array{0: string, 1?: mixed, 2?: Variable}>
     */
    public static function expandArrayEntries(
        Variable $spread,
        array $paramNames,
        ?int $variadicParamIndex,
        ?string $functionName = null
    ): array {
        return CallUnpack::expandArrayEntries($spread, $paramNames, $variadicParamIndex, $functionName);
    }
}
