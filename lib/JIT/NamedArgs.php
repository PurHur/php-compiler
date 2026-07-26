<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\BuiltinParamNames;
use PHPCompiler\JIT;
use PHPCompiler\JIT\Call\Native;
use PHPCompiler\VM\NamedArgs as VmNamedArgs;
use PHPCompiler\VM\TypeCheck as VmTypeCheck;
use PHPCompiler\VM\Variable as VmVariable;
use PHPCfg\Operand;
use PHPTypes\Type;
use PHPCfg\Operand\Literal;

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
        ?string $functionName = null,
        ?Context $context = null,
        bool $internalFunction = false
    ): array {
        if ([] === $entries) {
            return [[], []];
        }

        $normalized = self::normalizeEntries($entries, $operands);
        foreach ($normalized as $entry) {
            if ('n' === $entry['kind']) {
                return self::resolveMixed(
                    $normalized,
                    $paramNames,
                    $variadicParamIndex,
                    $functionName,
                    $context,
                    $internalFunction
                );
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
     * @return array{0: list<Variable>, 1: list<Operand|null>}|null
     */
    public static function tryCompileTimeResolveOutgoing(
        array $entries,
        array $operands,
        array $paramNames,
        ?int $variadicParamIndex,
        ?string $functionName,
        JIT $jit,
        ?Native $callee = null,
        bool $internalFunction = false
    ): ?array {
        $normalized = self::normalizeEntries($entries, $operands);
        $vmEntries = [];
        foreach ($normalized as $entry) {
            if ('n' === $entry['kind']) {
                $vmValue = CallUnpackCompileTime::tryCompileTimeValueFromJitVariable($entry['value']);
                if (null === $vmValue) {
                    return null;
                }
                $vmEntries[] = ['n', $entry['name'], $vmValue];
                continue;
            }
            $vmValue = CallUnpackCompileTime::tryCompileTimeValueFromJitVariable($entry['value']);
            if (null === $vmValue) {
                return null;
            }
            $vmEntries[] = ['p', $vmValue];
        }

        try {
            $vmResolved = VmNamedArgs::resolve(
                $vmEntries,
                $paramNames,
                $variadicParamIndex,
                $functionName,
                $internalFunction
            );
        } catch (\ArgumentCountError|\Error|\TypeError|\ValueError $e) {
            // Defer Zend call-binding errors to runtime so try/catch in user code works (#23449).
            return null;
        }
        if (
            null !== $variadicParamIndex
            && null !== $callee
            && null !== $callee->variadicArgIndex
            && self::calleeNeedsVariadicElementChecks($callee, $callee->variadicArgIndex)
            && isset($vmResolved[$variadicParamIndex])
            && VmVariable::TYPE_ARRAY === $vmResolved[$variadicParamIndex]->type
        ) {
            $elements = [];
            foreach ($vmResolved[$variadicParamIndex]->toArray()->iterate(true) as $value) {
                $elements[] = $value;
            }
            $typeConstraint = $callee->paramTypeConstraintsByArg[$callee->variadicArgIndex] ?? null;
            if (null !== $typeConstraint) {
                VmTypeCheck::verifyVariadicElements(
                    $elements,
                    $jit->context->callerStrictTypes,
                    $typeConstraint,
                    null,
                    $callee->paramIntersectionConstraintsByArg[$callee->variadicArgIndex] ?? null,
                    $callee->paramDnfConstraintsByArg[$callee->variadicArgIndex] ?? null,
                    $jit->context->runtime->vmContext,
                    false,
                    false,
                    null
                );
            }
        }

        $jitResult = [];
        $jitOperands = [];
        foreach ($vmResolved as $idx => $vmVar) {
            $jitVar = $jit->jitVariableFromVmConstantForCallUnpack($vmVar);
            if (
                null !== $variadicParamIndex
                && (int) $idx === $variadicParamIndex
                && Variable::TYPE_HASHTABLE === $jitVar->type
            ) {
                $jitVar->variadicElementChecksDone = true;
            }
            $jitResult[(int) $idx] = $jitVar;
            $jitOperands[(int) $idx] = null;
        }
        ksort($jitResult);
        ksort($jitOperands);

        return [$jitResult, $jitOperands];
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
    private static function resolveMixed(
        array $entries,
        array $paramNames,
        ?int $variadicParamIndex,
        ?string $functionName = null,
        ?Context $context = null,
        bool $internalFunction = false
    ): array {
        $paramCount = \count($paramNames);
        /** @var array<int, Variable> $result */
        $result = [];
        /** @var array<int, Operand|null> $resultOperands */
        $resultOperands = [];
        $filled = [];
        $nextPositional = 0;
        /** @var list<Variable> $variadicPositional */
        $variadicPositional = [];
        /** @var list<Operand|null> $variadicPositionalOperands */
        $variadicPositionalOperands = [];
        /** @var array<string, Variable> $variadicNamed */
        $variadicNamed = [];
        /** @var array<string, Operand|null> $variadicNamedOperands */
        $variadicNamedOperands = [];
        $unknownNamed = false;

        foreach ($entries as $entry) {
            if ('p' === $entry['kind']) {
                $value = $entry['value'];
                while ($nextPositional < $paramCount && isset($filled[$nextPositional])) {
                    ++$nextPositional;
                }
                if ($nextPositional < $paramCount && $nextPositional !== $variadicParamIndex) {
                    $filled[$nextPositional] = true;
                    $result[$nextPositional] = $value;
                    $resultOperands[$nextPositional] = $entry['operand'];
                    ++$nextPositional;
                    continue;
                }
                if (null !== $variadicParamIndex) {
                    if ([] !== $variadicNamed) {
                        $variadicPositional[] = $value;
                        $variadicPositionalOperands[] = $entry['operand'];
                        continue;
                    }
                    if ($nextPositional < $paramCount) {
                        $filled[$nextPositional] = true;
                        $result[$nextPositional] = $value;
                        $resultOperands[$nextPositional] = $entry['operand'];
                        ++$nextPositional;
                        continue;
                    }
                    $idx = \count($result);
                    $result[$idx] = $value;
                    $resultOperands[$idx] = $entry['operand'];
                    continue;
                }
                throw new \LogicException('Too many arguments to function call');
            }

            $name = (string) $entry['name'];
            $value = $entry['value'];
            if (null !== $functionName && BuiltinParamNames::rejectsNamedParameters($functionName)) {
                BuiltinParamNames::throwUnknownNamedParameterError($functionName);
            }
            $idx = BuiltinParamNames::lookupNamedParamIndex($paramNames, $name, $functionName);
            if (false === $idx) {
                if ($internalFunction) {
                    // Non-variadic internals: Zend Error "Unknown named parameter $x" (#23490).
                    // Variadics defer to too-few vs "does not accept unknown named" (#23449).
                    if (null === $variadicParamIndex) {
                        throw new \Error("Unknown named parameter \${$name}");
                    }
                    $unknownNamed = true;
                    continue;
                }
                if (null !== $variadicParamIndex) {
                    $key = (string) $entry['name'];
                    if (isset($variadicNamed[$key])) {
                        throw new \Error("Named parameter \${$key} overwrites previous argument");
                    }
                    $variadicNamed[$key] = $value;
                    $variadicNamedOperands[$key] = $entry['operand'];
                    continue;
                }
                throw new \Error("Unknown named parameter \${$entry['name']}");
            }
            if (null !== $variadicParamIndex && $idx === $variadicParamIndex) {
                if ($internalFunction) {
                    $unknownNamed = true;
                    continue;
                }
                $key = (string) $entry['name'];
                if (isset($variadicNamed[$key])) {
                    throw new \Error("Named parameter \${$key} overwrites previous argument");
                }
                $variadicNamed[$key] = $value;
                $variadicNamedOperands[$key] = $entry['operand'];
                continue;
            }
            if (isset($filled[$idx])) {
                throw new \Error("Named parameter \${$entry['name']} overwrites previous argument");
            }
            $filled[$idx] = true;
            $result[$idx] = $value;
            $resultOperands[$idx] = $entry['operand'];
        }

        if ($unknownNamed && null !== $functionName) {
            $required = BuiltinParamNames::requiredParamCountForInternalFunction($functionName) ?? 0;
            $given = 0;
            foreach ($result as $idx => $_) {
                if (null !== $variadicParamIndex && $idx === $variadicParamIndex) {
                    continue;
                }
                ++$given;
            }
            $given += \count($variadicPositional);
            if ($given < $required) {
                BuiltinParamNames::throwTooFewArgumentsError($functionName, $required, $given);
            }
            BuiltinParamNames::throwUnknownNamedParameterError($functionName);
        }

        if (null !== $variadicParamIndex && ([] !== $variadicNamed || [] !== $variadicPositional)) {
            if (null === $context) {
                throw new \LogicException('Variadic named argument packing requires JIT context');
            }
            self::assignVariadicArray(
                $context,
                $result,
                $resultOperands,
                $variadicParamIndex,
                $paramCount,
                $variadicPositional,
                $variadicPositionalOperands,
                $variadicNamed,
                $variadicNamedOperands
            );
        }

        ksort($result);
        ksort($resultOperands);

        return [$result, $resultOperands];
    }

    /**
     * @param array<int, Variable>     $result
     * @param array<int, Operand|null> $resultOperands
     * @param list<Variable>           $variadicPositional
     * @param list<Operand|null>       $variadicPositionalOperands
     * @param array<string, Variable>  $variadicNamed
     * @param array<string, Operand|null> $variadicNamedOperands
     */
    private static function assignVariadicArray(
        Context $context,
        array &$result,
        array &$resultOperands,
        int $variadicParamIndex,
        int $paramCount,
        array $variadicPositional,
        array $variadicPositionalOperands,
        array $variadicNamed,
        array $variadicNamedOperands
    ): void {
        $htVar = HashTableHelper::emptyVariable($context);
        $numIdx = 0;
        $i64 = $context->getTypeFromString('int64');
        if (isset($result[$variadicParamIndex])) {
            HashTableHelper::setAtIndex(
                $context,
                $htVar->value,
                $i64->constInt($numIdx, false),
                $result[$variadicParamIndex]
            );
            unset($result[$variadicParamIndex], $resultOperands[$variadicParamIndex]);
            ++$numIdx;
        }
        foreach ($variadicPositional as $value) {
            HashTableHelper::setAtIndex(
                $context,
                $htVar->value,
                $i64->constInt($numIdx, false),
                $value
            );
            ++$numIdx;
        }
        foreach ($variadicNamed as $key => $value) {
            $keyLit = new Literal((string) $key);
            $keyLit->type = Type::string();
            $keyVar = Variable::fromLiteral($context, $keyLit);
            HashTableHelper::addElement($context, $htVar, $value, $keyVar);
        }
        foreach ($result as $idx => $existing) {
            if ($idx > $variadicParamIndex && $idx >= $paramCount) {
                HashTableHelper::setAtIndex(
                    $context,
                    $htVar->value,
                    $i64->constInt($numIdx, false),
                    $existing
                );
                unset($result[$idx], $resultOperands[$idx]);
                ++$numIdx;
            }
        }
        $result[$variadicParamIndex] = $htVar;
        $resultOperands[$variadicParamIndex] = null;
    }

    private static function calleeNeedsVariadicElementChecks(Native $callee, int $llvmArgIndex): bool
    {
        return isset($callee->paramTypeConstraintsByArg[$llvmArgIndex])
            || isset($callee->paramIntersectionConstraintsByArg[$llvmArgIndex])
            || isset($callee->paramDnfConstraintsByArg[$llvmArgIndex])
            || isset($callee->paramClassConstraintsByArg[$llvmArgIndex]);
    }
}
