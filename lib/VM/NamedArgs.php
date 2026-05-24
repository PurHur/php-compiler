<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\VM\Variable;

/**
 * Resolve positional and named call arguments to definition order (issue #168).
 */
final class NamedArgs
{
    /**
     * @param list<array{0: string, 1?: mixed, 2?: Variable}> $entries 'p' + value, or 'n' + name + value
     * @param list<string>                                      $paramNames
     *
     * @return array<int, Variable>
     */
    public static function resolve(array $entries, array $paramNames, ?int $variadicParamIndex): array
    {
        if ([] === $entries) {
            return [];
        }

        foreach ($entries as $entry) {
            if ('n' === $entry[0]) {
                return self::resolveMixed($entries, $paramNames, $variadicParamIndex);
            }
        }

        $out = [];
        foreach ($entries as $entry) {
            $out[] = $entry[1];
        }

        return $out;
    }

    /**
     * @param list<array{0: string, 1?: mixed, 2?: Variable}> $entries
     * @param list<string>                                  $paramNames
     *
     * @return array<int, Variable>
     */
    private static function resolveMixed(array $entries, array $paramNames, ?int $variadicParamIndex): array
    {
        $paramCount = count($paramNames);
        $lowerNames = array_map('strtolower', $paramNames);
        /** @var array<int, Variable> $result */
        $result = [];
        $filled = [];
        $hadNamed = false;
        $nextPositional = 0;

        foreach ($entries as $entry) {
            if ('p' === $entry[0]) {
                if ($hadNamed) {
                    throw new \LogicException('Cannot use positional argument after named argument');
                }
                /** @var Variable $value */
                $value = $entry[1];
                while ($nextPositional < $paramCount && isset($filled[$nextPositional])) {
                    ++$nextPositional;
                }
                if ($nextPositional < $paramCount) {
                    $filled[$nextPositional] = true;
                    $result[$nextPositional] = $value;
                    ++$nextPositional;
                    continue;
                }
                if (null !== $variadicParamIndex) {
                    $idx = count($result);
                    $result[$idx] = $value;
                    continue;
                }
                throw new \LogicException('Too many arguments to function call');
            }

            $hadNamed = true;
            $name = strtolower((string) $entry[1]);
            /** @var Variable $value */
            $value = $entry[2];
            $idx = array_search($name, $lowerNames, true);
            if (false === $idx) {
                throw new \LogicException("Unknown named parameter \${$entry[1]}");
            }
            if (isset($filled[$idx])) {
                throw new \LogicException(
                    sprintf('Argument #%d ($%s) must be passed only once', $idx + 1, $paramNames[$idx])
                );
            }
            $filled[$idx] = true;
            $result[$idx] = $value;
        }

        return $result;
    }
}
