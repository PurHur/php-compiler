<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\BuiltinParamNames;
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
    public static function resolve(array $entries, array $paramNames, ?int $variadicParamIndex, ?string $functionName = null): array
    {
        if ([] === $entries) {
            return [];
        }

        foreach ($entries as $entry) {
            if ('n' === $entry[0]) {
                return self::resolveMixed($entries, $paramNames, $variadicParamIndex, $functionName);
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
    private static function resolveMixed(array $entries, array $paramNames, ?int $variadicParamIndex, ?string $functionName = null): array
    {
        $paramCount = count($paramNames);
        /** @var array<int, Variable> $result */
        $result = [];
        $filled = [];
        $nextPositional = 0;
        /** @var list<Variable> $variadicPositional */
        $variadicPositional = [];
        /** @var array<string, Variable> $variadicNamed */
        $variadicNamed = [];

        foreach ($entries as $entry) {
            if ('p' === $entry[0]) {
                /** @var Variable $value */
                $value = $entry[1];
                while ($nextPositional < $paramCount && isset($filled[$nextPositional])) {
                    ++$nextPositional;
                }
                if ($nextPositional < $paramCount && $nextPositional !== $variadicParamIndex) {
                    $filled[$nextPositional] = true;
                    $result[$nextPositional] = $value;
                    ++$nextPositional;
                    continue;
                }
                if (null !== $variadicParamIndex) {
                    if ([] !== $variadicNamed) {
                        $variadicPositional[] = $value;
                        continue;
                    }
                    if ($nextPositional < $paramCount) {
                        $filled[$nextPositional] = true;
                        $result[$nextPositional] = $value;
                        ++$nextPositional;
                        continue;
                    }
                    $idx = count($result);
                    $result[$idx] = $value;
                    continue;
                }
                throw new \LogicException('Too many arguments to function call');
            }

            $name = (string) $entry[1];
            /** @var Variable $value */
            $value = $entry[2];
            if (null !== $functionName && BuiltinParamNames::rejectsNamedParameters($functionName)) {
                BuiltinParamNames::throwUnknownNamedParameterError($functionName);
            }
            $idx = BuiltinParamNames::lookupNamedParamIndex($paramNames, $name, $functionName);
            if (false === $idx) {
                if (null !== $variadicParamIndex) {
                    $key = (string) $entry[1];
                    if (isset($variadicNamed[$key])) {
                        throw new \Error("Named parameter \${$key} overwrites previous argument");
                    }
                    $variadicNamed[$key] = $value;
                    continue;
                }
                throw new \Error("Unknown named parameter \${$entry[1]}");
            }
            if (null !== $variadicParamIndex && $idx === $variadicParamIndex) {
                $key = (string) $entry[1];
                if (isset($variadicNamed[$key])) {
                    throw new \Error("Named parameter \${$key} overwrites previous argument");
                }
                $variadicNamed[$key] = $value;
                continue;
            }
            if (isset($filled[$idx])) {
                throw new \Error("Named parameter \${$entry[1]} overwrites previous argument");
            }
            $filled[$idx] = true;
            $result[$idx] = $value;
        }

        if (null !== $variadicParamIndex && ([] !== $variadicNamed || [] !== $variadicPositional)) {
            self::assignVariadicArray($result, $variadicParamIndex, $paramCount, $variadicPositional, $variadicNamed);
        }

        ksort($result);

        return $result;
    }

    /**
     * @param array<int, Variable>     $result
     * @param list<Variable>           $variadicPositional
     * @param array<string, Variable>  $variadicNamed
     */
    private static function assignVariadicArray(
        array &$result,
        int $variadicParamIndex,
        int $paramCount,
        array $variadicPositional,
        array $variadicNamed
    ): void {
        $arrayVar = new Variable();
        $arrayVar->newArray();
        $packed = $arrayVar->toArray();
        $numIdx = 0;
        if (isset($result[$variadicParamIndex])) {
            $leading = new Variable();
            $leading->copyFrom($result[$variadicParamIndex]);
            $packed->addIndex($numIdx++, $leading);
            unset($result[$variadicParamIndex]);
        }
        foreach ($variadicPositional as $value) {
            $copy = new Variable();
            $copy->copyFrom($value);
            $packed->addIndex($numIdx++, $copy);
        }
        foreach ($variadicNamed as $key => $value) {
            $copy = new Variable();
            $copy->copyFrom($value);
            $packed->add($key, $copy);
        }
        foreach ($result as $idx => $existing) {
            if ($idx > $variadicParamIndex && $idx >= $paramCount) {
                $copy = new Variable();
                $copy->copyFrom($existing);
                $packed->addIndex($numIdx++, $copy);
                unset($result[$idx]);
            }
        }
        $result[$variadicParamIndex] = $arrayVar;
    }
}
