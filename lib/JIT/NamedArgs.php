<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCfg\Operand;

/**
 * Resolve positional and named JIT call arguments to definition order (issue #3777).
 */
final class NamedArgs
{
    /**
     * @param list<Variable|array{unpack: Variable}|array{named: string, value: Variable, operand?: Operand|null}> $entries
     * @param list<Operand|null>                                                                                    $operands parallel to $entries
     * @param list<string>                                                                                          $paramNames
     *
     * @return array{0: list<Variable>, 1: list<Operand|null>}
     */
    public static function resolveOutgoing(
        array $entries,
        array $operands,
        array $paramNames,
        ?int $variadicParamIndex
    ): array {
        if ([] === $entries) {
            return [[], []];
        }

        $normalized = self::normalizeEntries($entries, $operands);
        foreach ($normalized as $entry) {
            if ('n' === $entry['kind']) {
                return self::resolveMixed($normalized, $paramNames, $variadicParamIndex);
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
     * @param list<Variable|array{unpack: Variable}|array{named: string, value: Variable, operand?: Operand|null}> $entries
     * @param list<Operand|null>                                                                                    $operands
     *
     * @return list<array{kind: string, name?: string, value: Variable, operand: Operand|null}>
     */
    private static function normalizeEntries(array $entries, array $operands): array
    {
        $out = [];
        foreach ($entries as $i => $entry) {
            if (\is_array($entry) && isset($entry['unpack'])) {
                throw new \LogicException('Named arguments cannot be combined with argument unpacking in JIT');
            }
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
     * @param list<array{kind: string, name?: string, value: Variable, operand: Operand|null}> $entries
     * @param list<string>                                                                   $paramNames
     *
     * @return array{0: list<Variable>, 1: list<Operand|null>}
     */
    private static function resolveMixed(array $entries, array $paramNames, ?int $variadicParamIndex): array
    {
        $paramCount = \count($paramNames);
        $lowerNames = array_map('strtolower', $paramNames);
        /** @var array<int, Variable> $result */
        $result = [];
        /** @var array<int, Operand|null> $resultOperands */
        $resultOperands = [];
        $filled = [];
        $hadNamed = false;
        $nextPositional = 0;

        foreach ($entries as $entry) {
            if ('p' === $entry['kind']) {
                if ($hadNamed) {
                    throw new \LogicException('Cannot use positional argument after named argument');
                }
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

            $hadNamed = true;
            $name = strtolower((string) $entry['name']);
            $value = $entry['value'];
            $idx = array_search($name, $lowerNames, true);
            if (false === $idx) {
                throw new \LogicException("Unknown named parameter \${$entry['name']}");
            }
            if (isset($filled[$idx])) {
                throw new \LogicException(
                    \sprintf('Argument #%d ($%s) must be passed only once', $idx + 1, $paramNames[$idx])
                );
            }
            $filled[$idx] = true;
            $result[$idx] = $value;
            $resultOperands[$idx] = $entry['operand'];
        }

        return [$result, $resultOperands];
    }
}
