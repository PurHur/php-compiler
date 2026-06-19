<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\BuiltinParamNames;
use PHPCfg\Operand;

/**
 * Resolve positional and named JIT call arguments to definition order (issue #3777).
 */
final class NamedArgs
{
    /**
     * @param list<Variable|array<string, mixed>> $entries positional Variable, or named/unpack arrays
     * @param list<Operand|null>                  $operands parallel to $entries
     * @param list<string>                        $paramNames
     *
     * @return array{0: list<Variable>, 1: list<Operand|null>}
     */
    public static function resolveOutgoing(
        array $entries,
        array $operands,
        array $paramNames,
        ?int $variadicParamIndex,
        ?string $functionName = null
    ): array {
        if ([] === $entries) {
            return [[], []];
        }

        $normalized = self::normalizeEntries($entries, $operands);
        foreach ($normalized as $entry) {
            if ('n' === $entry['kind']) {
                return self::resolveMixed($normalized, $paramNames, $variadicParamIndex, $functionName);
            }
        }

        $values = [];
        $outOperands = [];
        foreach ($normalized as $entry) {
            $values[] = $entry['value'];
            $outOperands[] = $entry['operand'];
        }

        return [$values, $outOperands];
    }

    /**
     * @param list<Variable|array<string, mixed>> $entries
     * @param list<Operand|null>                  $operands
     *
     * @return list<array<string, mixed>>
     */
    private static function normalizeEntries(array $entries, array $operands): array
    {
        $out = [];
        foreach ($entries as $i => $entry) {
            if (\is_array($entry) && isset($entry['named'])) {
                $out[] = [
                    'kind' => 'n',
                    'name' => $entry['named'],
                    'value' => $entry['value'],
                    'operand' => $entry['operand'] ?? $operands[$i] ?? null,
                ];
                continue;
            }
            if (!($entry instanceof Variable)) {
                throw new \LogicException('Invalid JIT call argument entry');
            }
            $out[] = [
                'kind' => 'p',
                'value' => $entry,
                'operand' => $operands[$i] ?? null,
            ];
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $entries
     * @param list<string>               $paramNames
     *
     * @return array{0: list<Variable>, 1: list<Operand|null>}
     */
    private static function resolveMixed(array $entries, array $paramNames, ?int $variadicParamIndex, ?string $functionName = null): array
    {
        $paramCount = \count($paramNames);
        /** @var array<int, Variable> $result */
        $result = [];
        /** @var array<int, Operand|null> $resultOperands */
        $resultOperands = [];
        $filled = [];
        $nextPositional = 0;

        foreach ($entries as $entry) {
            if ('p' === $entry['kind']) {
                $value = $entry['value'];
                while ($nextPositional < $paramCount && isset($filled[$nextPositional])) {
                    ++$nextPositional;
                }
                if ($nextPositional < $paramCount) {
                    $filled[$nextPositional] = true;
                    $result[$nextPositional] = $value;
                    $resultOperands[$nextPositional] = $entry['operand'];
                    ++$nextPositional;
                    continue;
                }
                if (null !== $variadicParamIndex) {
                    $idx = \count($result);
                    $result[$idx] = $value;
                    $resultOperands[$idx] = $entry['operand'];
                    continue;
                }
                throw new \LogicException('Too many arguments to function call');
            }

            $name = (string) $entry['name'];
            $value = $entry['value'];
            $idx = BuiltinParamNames::lookupNamedParamIndex($paramNames, $name, $functionName);
            if (false === $idx) {
                throw new \Error("Unknown named parameter \${$entry['name']}");
            }
            if (isset($filled[$idx])) {
                throw new \Error("Named parameter \${$entry['name']} overwrites previous argument");
            }
            $filled[$idx] = true;
            $result[$idx] = $value;
            $resultOperands[$idx] = $entry['operand'];
        }

        ksort($result);
        ksort($resultOperands);

        return [$result, $resultOperands];
    }
}
