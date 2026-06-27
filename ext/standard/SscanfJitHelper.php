<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * sscanf() for compiled JIT/AOT modules (#9134, #12467 php-in-PHP).
 *
 * SSOT: {@see VmSscanf} (php-src ext/standard/sscanf.c).
 */
final class SscanfJitHelper
{
    private const TAG_NULL = 0;

    private const TAG_LONG = 1;

    private const TAG_DOUBLE = 2;

    private const TAG_BOOL = 3;

    private const TAG_STRING = 4;

    public static function parseToArray(string $input, string $format): ?HashTable
    {
        return VmSscanf::parseToArray($input, $format);
    }

    /**
     * By-ref assignment path: returns meta blob `assigned(q) + consumed(q) + encoded values`.
     */
    public static function parseAssignMeta(string $input, string $format, int $outCount): string
    {
        if ($outCount <= 0) {
            return self::packMeta(0, 0, '');
        }

        $outVars = [];
        for ($i = 0; $i < $outCount; ++$i) {
            $outVars[] = new Variable();
        }

        [$assigned, $consumed] = VmSscanf::parseWithConsumed($input, $format, $outVars);
        $payload = '';
        for ($i = 0; $i < $assigned; ++$i) {
            $payload .= self::encodeVariable($outVars[$i]->resolveIndirect());
        }

        return self::packMeta($assigned, $consumed, $payload);
    }

    private static function packMeta(int $assigned, int $consumed, string $payload): string
    {
        return \pack('qq', $assigned, $consumed).$payload;
    }

    private static function encodeVariable(Variable $value): string
    {
        switch ($value->type) {
            case Variable::TYPE_NULL:
                return \chr(self::TAG_NULL);
            case Variable::TYPE_INTEGER:
                return \chr(self::TAG_LONG).\pack('q', $value->toInt());
            case Variable::TYPE_FLOAT:
                return \chr(self::TAG_DOUBLE).\pack('d', $value->toFloat());
            case Variable::TYPE_BOOLEAN:
                return \chr(self::TAG_BOOL).\pack('q', $value->toBool() ? 1 : 0);
            case Variable::TYPE_STRING:
                $s = $value->toString();

                return \chr(self::TAG_STRING).\pack('q', \strlen($s)).$s;
            default:
                return \chr(self::TAG_LONG).\pack('q', $value->toInt());
        }
    }
}
