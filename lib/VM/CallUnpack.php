<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\BuiltinParamNames;
use PHPCompiler\CompilerVersion;
use PHPCompiler\Frame;
use PHPCompiler\VM;
use PHPCompiler\ext\standard\VmArray;

/** Call-time argument unpacking (`foo(...$x)`) for arrays and Traversables (Zend zend_API.c parity, #4452, #4669). */
final class CallUnpack
{
    public const NON_ARRAY_MESSAGE = 'Only arrays and Traversables can be unpacked';

    public const STRING_KEYS_MESSAGE = 'Cannot unpack array with string keys';

    /**
     * TypeError message for non-array / non-Traversable call unpack (zend_vm_def.h ZEND_SEND_UNPACK).
     *
     * PHP 8.4+ appends {@code , <type> given} via {@see EnumCaseSupport::typeNameForTypeErrorActual()} (#30023).
     */
    public static function nonArrayTypeErrorMessage(Variable $value): string
    {
        $message = self::NON_ARRAY_MESSAGE;
        if (!CompilerVersion::supportsUnpackTypeErrorGivenSuffix()) {
            return $message;
        }

        return $message.', '.EnumCaseSupport::typeNameForTypeErrorActual($value).' given';
    }

    /**
     * Expand call-time ...$spread into callArgEntries (positional / named) for NamedArgs::resolve().
     *
     * @param list<string> $paramNames
     *
     * @return list<array{0: string, 1?: mixed, 2?: Variable}>
     *
     * @throws \TypeError|\Error
     */
    /**
     * @param list<string> $paramNames
     *
     * @return list<array{0: string, 1?: mixed, 2?: Variable}>
     */
    public static function expandArrayEntries(
        Variable $spread,
        array $paramNames,
        ?int $variadicParamIndex,
        ?string $functionName = null,
        bool $fromUnpack = true
    ): array {
        $spread = $spread->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $spread->type) {
            throw new \LogicException('Expected array for call-time unpack');
        }

        return self::fromArray($spread, $paramNames, $variadicParamIndex, $functionName, $fromUnpack);
    }

    public static function expandToEntries(
        VM $vm,
        Frame $frame,
        Variable $spread,
        array $paramNames,
        ?int $variadicParamIndex,
        ?string $functionName = null
    ): array {
        $spread = $spread->resolveIndirect();

        if (Variable::TYPE_ARRAY === $spread->type) {
            return self::fromArray($spread, $paramNames, $variadicParamIndex, $functionName, true);
        }

        if (Variable::TYPE_OBJECT === $spread->type) {
            if (null !== $spread->toObject()->generatorState) {
                return self::fromGenerator($vm, $spread, $paramNames, $variadicParamIndex, $functionName);
            }
            try {
                $iterable = ForeachIterator::resolveTraversableObject($vm, $frame, $spread);
            } catch (\TypeError) {
                throw new \TypeError(self::nonArrayTypeErrorMessage($spread));
            }
            if (null !== $iterable->toObject()->generatorState) {
                return self::fromGenerator($vm, $iterable, $paramNames, $variadicParamIndex, $functionName);
            }

            return self::fromIteratorObject($vm, $frame, $iterable, $paramNames, $variadicParamIndex, $functionName);
        }

        throw new \TypeError(self::nonArrayTypeErrorMessage($spread));
    }

    /**
     * @param list<string> $paramNames
     *
     * @return list<array{0: string, 1?: mixed, 2?: Variable}>
     */
    private static function fromArray(
        Variable $array,
        array $paramNames,
        ?int $variadicParamIndex,
        ?string $functionName = null,
        bool $fromUnpack = true
    ): array {
        $ht = $array->toArray();
        // ZEND_SEND_UNPACK (zend_vm_def.h): send the array element cell, not a resolved
        // copy. By-ref formals then write back through the bucket — including through
        // [&$x] to the outer CV (#21913). Same cell-ref path as array_multisort (#15787).
        $pairs = [];
        foreach ($ht->iterateKeyed(false) as [$key, $_element]) {
            $bucket = $ht->findVariable($key, true);
            $ref = new Variable();
            $ref->indirect($bucket);
            $pairs[] = [$key, $ref];
        }
        if (VmArray::isList($ht)) {
            $out = [];
            foreach ($pairs as [, $ref]) {
                $out[] = ['p', $ref];
            }

            return $out;
        }

        return self::entriesFromKeyedPairs($pairs, $paramNames, $variadicParamIndex, $functionName, $fromUnpack);
    }

    /**
     * @param list<string> $paramNames
     *
     * @return list<array{0: string, 1?: mixed, 2?: Variable}>
     */
    private static function fromGenerator(
        VM $vm,
        Variable $genVar,
        array $paramNames,
        ?int $variadicParamIndex,
        ?string $functionName = null
    ): array {
        $gen = $genVar->toObject()->generatorState;
        // rewind() opens an unstarted generator onto the first yield (Zend
        // ZEND_GENERATOR_AT_FIRST_YIELD / #23713). Collect that yield before any
        // resume — resumeGenerator advances past the current value (#24646).
        $gen->rewind();
        $pairs = [];
        if ($gen->hasCurrent && !$gen->done) {
            do {
                $value = new Variable();
                $value->copyFrom($gen->currentValue);
                $keyCopy = new Variable();
                $keyCopy->copyFrom($gen->currentKey);
                $pairs[] = [$keyCopy, $value];
            } while ($vm->resumeGenerator($gen));
        }

        return self::entriesFromKeyedPairs($pairs, $paramNames, $variadicParamIndex, $functionName, true);
    }

    /**
     * @param list<string> $paramNames
     *
     * @return list<array{0: string, 1?: mixed, 2?: Variable}>
     */
    private static function fromIteratorObject(
        VM $vm,
        Frame $frame,
        Variable $iterable,
        array $paramNames,
        ?int $variadicParamIndex,
        ?string $functionName = null
    ): array {
        $vm->invokeForeachInstanceMethod($frame, $iterable, 'rewind');
        $pairs = [];
        while ($vm->invokeForeachInstanceMethod($frame, $iterable, 'valid')->toBool()) {
            $key = $vm->invokeForeachInstanceMethod($frame, $iterable, 'key');
            $current = $vm->invokeForeachInstanceMethod($frame, $iterable, 'current');
            $value = new Variable();
            $value->copyFrom($current);
            $pairs[] = [$key, $value];
            $vm->invokeForeachInstanceMethod($frame, $iterable, 'next');
        }

        return self::entriesFromKeyedPairs($pairs, $paramNames, $variadicParamIndex, $functionName, true);
    }

    /**
     * @param iterable<int, array{0: Variable, 1: Variable}> $pairs
     * @param list<string>                                   $paramNames
     *
     * @return list<array{0: string, 1?: mixed, 2?: Variable}>
     */
    private static function entriesFromKeyedPairs(
        iterable $pairs,
        array $paramNames,
        ?int $variadicParamIndex,
        ?string $functionName = null,
        bool $fromUnpack = true
    ): array {
        $paramCount = \count($paramNames);
        $entries = [];
        $hadNamed = false;
        $nextPositional = 0;
        $filled = [];

        foreach ($pairs as $pair) {
            $key = $pair[0]->resolveIndirect();
            $value = $pair[1];
            if (self::isPositionalUnpackKey($key)) {
                if ($hadNamed) {
                    throw new \Error(
                        $fromUnpack
                            ? 'Cannot use positional argument after named argument during unpacking'
                            : 'Cannot use positional argument after named argument'
                    );
                }
                while ($nextPositional < $paramCount && isset($filled[$nextPositional])) {
                    ++$nextPositional;
                }
                if ($nextPositional < $paramCount) {
                    $filled[$nextPositional] = true;
                    ++$nextPositional;
                } elseif (null === $variadicParamIndex) {
                    throw new \LogicException('Too many arguments to function call');
                }
                $entries[] = ['p', $value];
                continue;
            }
            if (Variable::TYPE_STRING !== $key->type) {
                throw new \TypeError(self::STRING_KEYS_MESSAGE);
            }
            $hadNamed = true;
            $name = $key->toString();
            if (null !== $functionName && BuiltinParamNames::rejectsNamedParameters($functionName)) {
                BuiltinParamNames::throwUnknownNamedParameterError($functionName);
            }
            $idx = BuiltinParamNames::lookupNamedParamIndex($paramNames, $name, $functionName);
            if (false === $idx) {
                if (null !== $variadicParamIndex) {
                    $entries[] = ['n', $name, $value];
                    continue;
                }
                throw new \Error("Unknown named parameter \${$name}");
            }
            if (isset($filled[$idx])) {
                throw new \Error("Named parameter \${$name} overwrites previous argument");
            }
            $filled[$idx] = true;
            $entries[] = ['n', $name, $value];
        }

        return $entries;
    }

    private static function isPositionalUnpackKey(Variable $key): bool
    {
        if (Variable::TYPE_INTEGER === $key->type) {
            return true;
        }
        if (Variable::TYPE_STRING === $key->type) {
            $s = $key->toString();

            return '' !== $s && (string) (int) $s === $s && (int) $s >= 0;
        }

        return false;
    }
}
