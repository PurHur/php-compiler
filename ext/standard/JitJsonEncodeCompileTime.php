<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Block;
use PHPCompiler\JIT\Builtin\StringJsonDecode;
use PHPCompiler\JIT\CallUnpackHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\OpCode;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable as VmVariable;
use PHPCfg\Operand;
use PHPCfg\Operand\Literal;
use PHPLLVM\Value;

/**
 * Compile-time json_encode() for inline array literals — avoids deferred AOT stubs (#14040).
 *
 * Also folds `json_encode(preg_split(lit…))` when args are compile-time (#27080),
 * `json_encode(mb_str_split(lit…))` (#27242),
 * `json_encode(mb_encoding_aliases(lit))` / `json_encode(mb_list_encodings())` (#30795),
 * `json_encode(preg_filter(lit…))` / string-subject (#27181),
 * `json_encode(array_replace_recursive(lit…))` (#26977),
 * `json_encode(array_flip(lit…))` (#27072),
 * `json_encode(array_combine(lit, lit))` (#27132),
 * `json_encode(array_merge(lit…))` (#27546),
 * `json_encode([...$lit, ...])` array unpack via CallUnpackCompileTime (#28673),
 * `json_encode(array_reverse(lit…))` (#27130),
 * `json_encode(array_column(lit…))` (#27131),
 * `json_encode(array_fill(lit, lit, lit))` (#27073),
 * `json_encode(array_fill_keys(lit, lit))` (#27127),
 * `json_encode(array_change_key_case(lit…))` (#27183), and
 * `json_encode(parse_url(lit…))` (#27078) — thin AOT NestedJIT cannot yet
 * export runtime string-key hashtables for `__compiler_json_encode_array`.
 *
 * php-src: ext/json/php_json.c — php_json_encode
 */
final class JitJsonEncodeCompileTime
{
    public static function tryEncode(
        Context $context,
        ?Block $block,
        ?Operand $operand,
        int $flags
    ): ?Value {
        if (null === $block || null === $operand) {
            return null;
        }
        $stdClass = self::tryCompileTimeStdClassFromOperand($block, $operand);
        if (null !== $stdClass) {
            return self::emitEncodedExported($context, $stdClass, $flags);
        }
        $vmArray = CallUnpackHelper::tryCompileTimeArrayFromOperand($block, $operand);
        if (null === $vmArray) {
            $vmArray = self::tryCompileTimeArrayFromPregSplit($block, $operand);
        }
        if (null === $vmArray) {
            $vmArray = self::tryCompileTimeArrayFromMbStrSplit($block, $operand);
        }
        if (null === $vmArray) {
            $vmArray = self::tryCompileTimeArrayFromMbEncodingAliases($block, $operand);
        }
        if (null === $vmArray) {
            $vmArray = self::tryCompileTimeArrayFromMbListEncodings($block, $operand);
        }
        if (null === $vmArray) {
            $vmArray = self::tryCompileTimeArrayFromPregFilter($block, $operand);
        }
        if (null === $vmArray) {
            $vmArray = self::tryCompileTimeArrayFromArrayReplaceRecursive($block, $operand);
        }
        if (null === $vmArray) {
            $vmArray = self::tryCompileTimeArrayFromArrayFlip($block, $operand);
        }
        if (null === $vmArray) {
            $vmArray = self::tryCompileTimeArrayFromArrayCombine($block, $operand);
        }
        if (null === $vmArray) {
            $vmArray = self::tryCompileTimeArrayFromArrayMerge($block, $operand);
        }
        if (null === $vmArray) {
            $vmArray = self::tryCompileTimeArrayFromArrayReverse($block, $operand);
        }
        if (null === $vmArray) {
            $vmArray = self::tryCompileTimeArrayFromArrayColumn($block, $operand);
        }
        if (null === $vmArray) {
            $vmArray = self::tryCompileTimeArrayFromArrayFill($block, $operand);
        }
        if (null === $vmArray) {
            $vmArray = self::tryCompileTimeArrayFromArrayFillKeys($block, $operand);
        }
        if (null === $vmArray) {
            $vmArray = self::tryCompileTimeArrayFromArrayChangeKeyCase($block, $operand);
        }
        if (null === $vmArray) {
            $vmArray = self::tryCompileTimeArrayFromParseUrl($block, $operand);
        }
        if (null === $vmArray) {
            $foldedFalse = self::tryEncodePregSplitFalse($context, $block, $operand, $flags);
            if (null !== $foldedFalse) {
                return $foldedFalse;
            }
            $foldedPregFilter = self::tryEncodePregFilterScalar($context, $block, $operand, $flags);
            if (null !== $foldedPregFilter) {
                return $foldedPregFilter;
            }
            $foldedParseUrlFalse = self::tryEncodeParseUrlFalse($context, $block, $operand, $flags);
            if (null !== $foldedParseUrlFalse) {
                return $foldedParseUrlFalse;
            }

            return null;
        }
        try {
            $exported = VmJson::export($vmArray);
        } catch (VmJsonExportException $e) {
            if (VmJsonFlags::throwsOnError($flags)) {
                // Prefer catchable emit over aborting AOT with host JsonException (#27623).
                return JitJsonThrow::emitFromException(
                    $context,
                    new \JsonException(VmJson::errorMsgForCode($e->errorCode), $e->errorCode)
                );
            }
            // Soft-fail: bake false + sticky last_error (avoid AOT runtime INF crash, #26792).
            self::emitSetLastError($context, $e->errorCode);

            return self::emitFalse($context);
        }
        try {
            $encoded = VmJsonFormat::encodeExported($exported, $flags);
        } catch (\JsonException $e) {
            return JitJsonThrow::emitFromException($context, $e);
        }
        $sticky = VmJson::lastError();
        if (false === $encoded) {
            if (VmJsonFlags::throwsOnError($flags)) {
                throw new \LogicException('json_encode() THROW path returned false');
            }
            self::emitSetLastError($context, $sticky);

            return self::emitFalse($context);
        }
        // PARTIAL substitutions leave sticky JSON_ERROR_* while still returning a string —
        // emit runtime set so last_error* observe it (#26792 / php-src json.c).
        if (0 !== $sticky) {
            self::emitSetLastError($context, $sticky);
        }

        return $context->builder->load($context->constantStringFromString($encoded));
    }

    /** Publish sticky/cleared JSON_ERROR_* into NestedJIT validate TU (#26792). */
    public static function emitSetLastError(Context $context, int $code): void
    {
        StringJsonDecode::ensureLinked($context);
        $context->builder->call(
            $context->lookupFunction('__compiler_json_set_last_error'),
            $context->getTypeFromString('int64')->constInt($code, false)
        );
    }

    /** @return Value __value__* bool false */
    private static function emitFalse(Context $context): Value
    {
        $slot = JitValueBox::alloc($context);
        JitValueBox::writeBool($context, $slot, $context->constantFromBool(false));

        return JitValueBox::pointer($context, $slot);
    }

    /**
     * True when the operand is rooted at `new ClassName` (class-name stamp, not a string) (#28638).
     */
    public static function operandIsNewObject(?Block $block, ?Operand $operand): bool
    {
        if (null === $block || null === $operand) {
            return false;
        }

        return null !== self::findNewResultSlot($block, $block->getVarSlot($operand, true));
    }

    /**
     * Fold `json_encode($o)` when $o is `new stdClass` plus literal property writes (#28638).
     *
     * Thin AOT NestedJIT cannot yet encode get_object_vars() hashtables (SIGSEGV) — same
     * class of gap as array_* folds above. php-src: ext/json/json_encoder.c object walk.
     */
    private static function tryCompileTimeStdClassFromOperand(Block $block, Operand $operand): ?\stdClass
    {
        $holderSlot = $block->getVarSlot($operand, true);
        $newSlot = self::findNewResultSlot($block, $holderSlot);
        if (null === $newSlot || !self::newResultIsStdClass($block, $newSlot)) {
            return null;
        }
        $props = self::collectLiteralPropertyWrites($block, $holderSlot);
        if (null === $props) {
            return null;
        }
        $obj = new \stdClass();
        foreach ($props as $name => $value) {
            $obj->{$name} = $value;
        }

        return $obj;
    }

    /** @return Value|null */
    private static function emitEncodedExported(Context $context, mixed $exported, int $flags): ?Value
    {
        try {
            $encoded = VmJsonFormat::encodeExported($exported, $flags);
        } catch (VmJsonExportException $e) {
            if (VmJsonFlags::throwsOnError($flags)) {
                return JitJsonThrow::emitFromException(
                    $context,
                    new \JsonException(VmJson::errorMsgForCode($e->errorCode), $e->errorCode)
                );
            }
            self::emitSetLastError($context, $e->errorCode);

            return self::emitFalse($context);
        } catch (\JsonException $e) {
            return JitJsonThrow::emitFromException($context, $e);
        }
        $sticky = VmJson::lastError();
        if (false === $encoded) {
            if (VmJsonFlags::throwsOnError($flags)) {
                throw new \LogicException('json_encode() THROW path returned false');
            }
            self::emitSetLastError($context, $sticky);

            return self::emitFalse($context);
        }
        if (0 !== $sticky) {
            self::emitSetLastError($context, $sticky);
        }

        return $context->builder->load($context->constantStringFromString($encoded));
    }

    /**
     * Walk ASSIGN chains to the TYPE_NEW result slot, or null if not from `new`.
     *
     * @param array<int, true> $visited
     */
    private static function findNewResultSlot(Block $block, int $slot, array &$visited = []): ?int
    {
        if (isset($visited[$slot])) {
            return null;
        }
        $visited[$slot] = true;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_NEW === $op->type && $op->arg1 === $slot) {
                return $slot;
            }
        }
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_ASSIGN !== $op->type || $op->arg2 !== $slot || null === $op->arg3) {
                continue;
            }
            $found = self::findNewResultSlot($block, (int) $op->arg3, $visited);
            if (null !== $found) {
                return $found;
            }
        }

        return null;
    }

    private static function newResultIsStdClass(Block $block, int $newResultSlot): bool
    {
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_NEW !== $op->type || $op->arg1 !== $newResultSlot) {
                continue;
            }
            if (null === $op->arg2 || !isset($block->constants[$op->arg2])) {
                return false;
            }
            $class = $block->constants[$op->arg2];
            if (VmVariable::TYPE_STRING !== $class->type) {
                return false;
            }

            return 0 === \strcasecmp(\ltrim($class->toString(), '\\'), 'stdClass');
        }

        return false;
    }

    /**
     * Literal `$obj->prop = …` writes on $objSlot. Null if any write is non-literal.
     *
     * @return array<string, mixed>|null
     */
    private static function collectLiteralPropertyWrites(Block $block, int $objSlot): ?array
    {
        $props = [];
        $n = \count($block->opCodes);
        for ($i = 0; $i < $n; ++$i) {
            $op = $block->opCodes[$i];
            if (
                OpCode::TYPE_PROPERTY_FETCH !== $op->type
                && OpCode::TYPE_PROPERTY_FETCH_WRITE !== $op->type
            ) {
                continue;
            }
            if ($op->arg2 !== $objSlot || null === $op->arg1 || null === $op->arg3) {
                continue;
            }
            if (!isset($block->constants[$op->arg3]) || VmVariable::TYPE_STRING !== $block->constants[$op->arg3]->type) {
                return null;
            }
            $name = $block->constants[$op->arg3]->toString();
            $propSlot = (int) $op->arg1;
            $assigned = false;
            for ($j = $i + 1; $j < $n; ++$j) {
                $assign = $block->opCodes[$j];
                if (OpCode::TYPE_ASSIGN !== $assign->type || $assign->arg2 !== $propSlot || null === $assign->arg3) {
                    continue;
                }
                $php = self::compileTimeExportedValue($block, (int) $assign->arg3);
                if (false === $php) {
                    return null;
                }
                $props[$name] = $php;
                $assigned = true;
                break;
            }
            if (!$assigned) {
                continue;
            }
        }

        return $props;
    }

    /**
     * @return mixed|false false = not a compile-time scalar/array
     */
    private static function compileTimeExportedValue(Block $block, int $slot): mixed
    {
        if (!isset($block->constants[$slot])) {
            return false;
        }
        $vm = new VmVariable();
        $vm->copyFrom($block->constants[$slot]);
        try {
            return VmJson::export($vm);
        } catch (VmJsonExportException $e) {
            return false;
        }
    }

    /**
     * Resolve `json_encode(preg_split(…))` when pattern/subject(/limit/flags) are literals (#27080).
     */
    private static function tryCompileTimeArrayFromPregSplit(Block $block, Operand $operand): ?VmVariable
    {
        $parts = self::evalCompileTimePregSplit($block, $operand);
        if (null === $parts || false === $parts) {
            return null;
        }
        $ht = VmPreg::splitPartsToHashTable($parts, self::$lastPregSplitFlags);
        $var = new VmVariable();
        $var->array($ht);

        return $var;
    }

    /**
     * Resolve `json_encode(mb_str_split(…))` when string(/length/encoding) are literals (#27242).
     *
     * Peer {@see tryCompileTimeArrayFromPregSplit}: thin AOT `__compiler_json_encode_array`
     * cannot export the NestedJIT HT from {@see \PHPCompiler\ext\mbstring\JitMbStrSplit}.
     */
    private static function tryCompileTimeArrayFromMbStrSplit(Block $block, Operand $operand): ?VmVariable
    {
        $parts = self::evalCompileTimeMbStrSplit($block, $operand);
        if (null === $parts) {
            return null;
        }

        return self::vmArrayFromStringList($parts);
    }

    /**
     * Resolve `json_encode(mb_encoding_aliases(lit))` (#30795).
     *
     * Peer {@see tryCompileTimeArrayFromMbStrSplit}: thin AOT cannot export the folded HT.
     */
    private static function tryCompileTimeArrayFromMbEncodingAliases(Block $block, Operand $operand): ?VmVariable
    {
        $parts = self::evalCompileTimeMbEncodingAliases($block, $operand);
        if (null === $parts) {
            return null;
        }

        return self::vmArrayFromStringList($parts);
    }

    /**
     * Resolve `json_encode(mb_list_encodings())` (#30795).
     *
     * Peer {@see tryCompileTimeArrayFromMbStrSplit}: thin AOT cannot export the folded HT.
     */
    private static function tryCompileTimeArrayFromMbListEncodings(Block $block, Operand $operand): ?VmVariable
    {
        $parts = self::evalCompileTimeMbListEncodings($block, $operand);
        if (null === $parts) {
            return null;
        }

        return self::vmArrayFromStringList($parts);
    }

    /** @param list<string> $parts */
    private static function vmArrayFromStringList(array $parts): VmVariable
    {
        $ht = new HashTable();
        foreach ($parts as $piece) {
            $value = new VmVariable();
            $value->string($piece);
            $ht->append($value);
        }
        $var = new VmVariable();
        $var->array($ht);

        return $var;
    }

    /**
     * @return list<string>|null null = not a foldable mb_str_split result
     */
    private static function evalCompileTimeMbStrSplit(Block $block, Operand $operand): ?array
    {
        $slot = $block->getVarSlot($operand, true);
        $slot = self::followAssignSourceSlot($block, $slot);
        $args = self::literalArgsForFuncCallResult($block, $slot, 'mb_str_split');
        if (null === $args) {
            return null;
        }
        $argc = \count($args);
        if ($argc < 1 || $argc > 3) {
            return null;
        }
        if (VmVariable::TYPE_STRING !== $args[0]->type) {
            return null;
        }
        $string = $args[0]->toString();
        $length = 1;
        if ($argc >= 2) {
            if (VmVariable::TYPE_INTEGER !== $args[1]->type) {
                return null;
            }
            $length = $args[1]->toInt();
            if ($length <= 0) {
                return null;
            }
        }
        $encoding = 'UTF-8';
        if ($argc >= 3) {
            if (VmVariable::TYPE_STRING !== $args[2]->type) {
                return null;
            }
            $encoding = $args[2]->toString();
        }
        try {
            return \PHPCompiler\ext\mbstring\VmMbstring::strSplit($string, $length, $encoding);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * @return list<string>|null null = not a foldable mb_encoding_aliases result
     */
    private static function evalCompileTimeMbEncodingAliases(Block $block, Operand $operand): ?array
    {
        $slot = $block->getVarSlot($operand, true);
        $slot = self::followAssignSourceSlot($block, $slot);
        $args = self::literalArgsForFuncCallResult($block, $slot, 'mb_encoding_aliases');
        if (null === $args || 1 !== \count($args)) {
            return null;
        }
        if (VmVariable::TYPE_STRING !== $args[0]->type) {
            return null;
        }
        $encoding = $args[0]->toString();
        $canonical = \PHPCompiler\ext\mbstring\MbstringEncodingRegistry::resolve($encoding);
        if (null === $canonical) {
            // Invalid encoding → runtime ValueError from {@see JitMbEncodingRegistry}; do not fold.
            return null;
        }

        return \PHPCompiler\ext\mbstring\MbstringEncodingRegistry::aliases($canonical);
    }

    /**
     * @return list<string>|null null = not a foldable mb_list_encodings result
     */
    private static function evalCompileTimeMbListEncodings(Block $block, Operand $operand): ?array
    {
        $slot = $block->getVarSlot($operand, true);
        $slot = self::followAssignSourceSlot($block, $slot);
        $args = self::literalArgsForFuncCallResult($block, $slot, 'mb_list_encodings');
        if (null === $args || [] !== $args) {
            return null;
        }

        return \PHPCompiler\ext\mbstring\MbstringEncodingRegistry::listEncodings();
    }

    /**
     * Resolve `json_encode(preg_filter(…))` when args are literals and the subject is an array (#27181).
     */
    private static function tryCompileTimeArrayFromPregFilter(Block $block, Operand $operand): ?VmVariable
    {
        $result = self::evalCompileTimePregFilter($block, $operand);
        if (null === $result || false === $result || !\is_array($result)) {
            return null;
        }
        $ht = new HashTable();
        foreach ($result as $key => $line) {
            $keyVar = new VmVariable();
            if (\is_int($key)) {
                $keyVar->int($key);
            } else {
                $keyVar->string((string) $key);
            }
            $value = new VmVariable();
            $value->string((string) $line);
            array_map::appendKeyedCopy($ht, $keyVar, $value);
        }
        $var = new VmVariable();
        $var->array($ht);

        return $var;
    }

    /**
     * Fold `json_encode(preg_filter(…))` when the host result is string/null/false (#27181).
     */
    private static function tryEncodePregFilterScalar(
        Context $context,
        Block $block,
        Operand $operand,
        int $flags
    ): ?Value {
        $slot = $block->getVarSlot($operand, true);
        $slot = self::followAssignSourceSlot($block, $slot);
        $args = self::literalArgsForFuncCallResult($block, $slot, 'preg_filter');
        if (null === $args) {
            return null;
        }
        $result = self::evalPregFilterFromLiteralArgs($args);
        if (\is_array($result)) {
            // Array results go through tryCompileTimeArrayFromPregFilter.
            return null;
        }
        if (null === $result) {
            // Host null only for string subject no-match; otherwise args were unusable.
            if (!isset($args[2]) || VmVariable::TYPE_STRING !== $args[2]->type) {
                return null;
            }
        }
        // string | null | false
        $encoded = VmJsonFormat::encodeExported($result, $flags);
        if (false === $encoded) {
            return self::emitFalse($context);
        }

        return $context->builder->load($context->constantStringFromString($encoded));
    }

    /**
     * @return array<int|string, string>|string|false|null
     *         null = not foldable; false = PCRE error; array/string/null-as-exported via scalar path
     */
    private static function evalCompileTimePregFilter(Block $block, Operand $operand): array|string|false|null
    {
        $slot = $block->getVarSlot($operand, true);
        $slot = self::followAssignSourceSlot($block, $slot);
        $args = self::literalArgsForFuncCallResult($block, $slot, 'preg_filter');
        if (null === $args) {
            return null;
        }

        return self::evalPregFilterFromLiteralArgs($args);
    }

    /**
     * @param list<VmVariable> $args
     *
     * @return array<int|string, string>|string|false|null host preg_filter result, or null if args unusable
     */
    private static function evalPregFilterFromLiteralArgs(array $args): array|string|false|null
    {
        $argc = \count($args);
        if ($argc < 3 || $argc > 5) {
            return null;
        }
        if (VmVariable::TYPE_STRING !== $args[0]->type || VmVariable::TYPE_STRING !== $args[1]->type) {
            return null;
        }
        $pattern = $args[0]->toString();
        $replacement = $args[1]->toString();
        $limit = -1;
        if ($argc >= 4) {
            if (VmVariable::TYPE_INTEGER !== $args[3]->type) {
                return null;
            }
            $limit = $args[3]->toInt();
        }
        if (VmVariable::TYPE_STRING === $args[2]->type) {
            return \preg_filter($pattern, $replacement, $args[2]->toString(), $limit);
        }
        if (VmVariable::TYPE_ARRAY !== $args[2]->type) {
            return null;
        }
        $host = [];
        foreach ($args[2]->toArray()->iterateKeyed(true) as [$key, $value]) {
            $hostKey = VmVariable::TYPE_INTEGER === $key->type
                ? $key->toInt()
                : $key->toString();
            $host[$hostKey] = $value->resolveIndirect()->toString();
        }

        return \preg_filter($pattern, $replacement, $host, $limit);
    }

    /**
     * Resolve `json_encode(array_replace_recursive(…))` when all args are compile-time arrays (#26977).
     *
     * Uses VM {@see \PHPCompiler\VM\HashTable::replaceRecursiveCopy()} SSOT (php-src array.c).
     */
    private static function tryCompileTimeArrayFromArrayReplaceRecursive(
        Block $block,
        Operand $operand
    ): ?VmVariable {
        $slot = $block->getVarSlot($operand, true);
        $slot = self::followAssignSourceSlot($block, $slot);
        $args = self::literalArgsForFuncCallResult($block, $slot, 'array_replace_recursive');
        if (null === $args || [] === $args) {
            return null;
        }
        foreach ($args as $arg) {
            if (VmVariable::TYPE_ARRAY !== $arg->type) {
                return null;
            }
        }
        $first = $args[0]->toArray();
        $others = [];
        for ($i = 1, $n = \count($args); $i < $n; ++$i) {
            $others[] = $args[$i]->toArray();
        }
        $merged = $first->replaceRecursiveCopy(...$others);
        $var = new VmVariable();
        $var->array($merged);

        return $var;
    }

    /**
     * Resolve `json_encode(array_flip(…))` when the array arg is compile-time (#27072).
     *
     * Uses VM {@see VmArray::flip()} SSOT (php-src array.c). array_flip call-site LLVM
     * already builds the flipped map (foreach/dim green); NestedJIT json_encode still
     * exports string keys as empty (`{}`) without this fold.
     */
    private static function tryCompileTimeArrayFromArrayFlip(
        Block $block,
        Operand $operand
    ): ?VmVariable {
        $slot = $block->getVarSlot($operand, true);
        $slot = self::followAssignSourceSlot($block, $slot);
        $args = self::literalArgsForFuncCallResult($block, $slot, 'array_flip');
        if (null === $args || 1 !== \count($args)) {
            return null;
        }
        if (VmVariable::TYPE_ARRAY !== $args[0]->type) {
            return null;
        }
        $flipped = VmArray::flip($args[0]->toArray());
        $var = new VmVariable();
        $var->array($flipped);

        return $var;
    }

    /**
     * Resolve `json_encode(array_combine(…))` when both args are compile-time arrays (#27132).
     *
     * Uses VM {@see VmArray::combine()} SSOT (php-src array.c). Call-site
     * {@see \PHPCompiler\JIT\HashTableCombineLlvm} builds a dim/count-green HT; NestedJIT
     * json_encode still cannot export runtime string-key tables (peer #27072 / #26977).
     */
    private static function tryCompileTimeArrayFromArrayCombine(
        Block $block,
        Operand $operand
    ): ?VmVariable {
        $slot = $block->getVarSlot($operand, true);
        $slot = self::followAssignSourceSlot($block, $slot);
        $args = self::literalArgsForFuncCallResult($block, $slot, 'array_combine');
        if (null === $args || 2 !== \count($args)) {
            return null;
        }
        if (VmVariable::TYPE_ARRAY !== $args[0]->type || VmVariable::TYPE_ARRAY !== $args[1]->type) {
            return null;
        }
        try {
            $combined = VmArray::combine($args[0]->toArray(), $args[1]->toArray());
        } catch (\ValueError) {
            // Length mismatch — leave to runtime ValueError path (#16080).
            return null;
        }
        $var = new VmVariable();
        $var->array($combined);

        return $var;
    }

    /**
     * Resolve `json_encode(array_merge(…))` when all args are compile-time arrays (#27546).
     *
     * Uses VM {@see VmArray::merge()} SSOT (php-src array.c). Call-site
     * {@see \PHPCompiler\JIT\HashTableMergeLlvm} builds a dim/count/implode-green HT;
     * NestedJIT json_encode still cannot export those runtime tables (peer #27132 / #27072).
     */
    private static function tryCompileTimeArrayFromArrayMerge(
        Block $block,
        Operand $operand
    ): ?VmVariable {
        $slot = $block->getVarSlot($operand, true);
        $slot = self::followAssignSourceSlot($block, $slot);
        $args = self::literalArgsForFuncCallResult($block, $slot, 'array_merge');
        if (null === $args || [] === $args) {
            return null;
        }
        foreach ($args as $arg) {
            if (VmVariable::TYPE_ARRAY !== $arg->type) {
                return null;
            }
        }
        $first = $args[0]->toArray();
        $others = [];
        for ($i = 1, $n = \count($args); $i < $n; ++$i) {
            $others[] = $args[$i]->toArray();
        }
        $merged = VmArray::merge($first, ...$others);
        $var = new VmVariable();
        $var->array($merged);

        return $var;
    }

    /**
     * Resolve `json_encode(array_reverse(…))` when args are compile-time (#27130).
     *
     * Uses {@see ArrayReverseJitHelper::reverseCopy()} / {@see \PHPCompiler\VM\HashTable::reverseCopy()}
     * SSOT (php-src array.c). Call-site {@see \PHPCompiler\JIT\HashTableReverseLlvm} builds a
     * dim/count/foreach-green HT; NestedJIT json_encode still cannot export those runtime
     * tables (peer #27546 / #27132 / #27072).
     */
    private static function tryCompileTimeArrayFromArrayReverse(
        Block $block,
        Operand $operand
    ): ?VmVariable {
        $slot = $block->getVarSlot($operand, true);
        $slot = self::followAssignSourceSlot($block, $slot);
        $args = self::literalArgsForFuncCallResult($block, $slot, 'array_reverse');
        if (null === $args || 0 === \count($args) || \count($args) > 2) {
            return null;
        }
        if (VmVariable::TYPE_ARRAY !== $args[0]->type) {
            return null;
        }
        $preserve = false;
        if (2 === \count($args)) {
            if (VmVariable::TYPE_BOOLEAN === $args[1]->type) {
                $preserve = $args[1]->toBool();
            } elseif (VmVariable::TYPE_INTEGER === $args[1]->type) {
                $preserve = 0 !== $args[1]->toInt();
            } else {
                return null;
            }
        }
        $reversed = ArrayReverseJitHelper::reverseCopy($args[0]->toArray(), $preserve);
        $var = new VmVariable();
        $var->array($reversed);

        return $var;
    }

    /**
     * Resolve `json_encode(array_column(…))` when args are compile-time (#27131).
     *
     * Uses {@see ArrayColumnJitHelper} SSOT (php-src array.c). Call-site
     * {@see \PHPCompiler\JIT\ArrayColumnLlvm} builds a dim/count/implode-green HT; NestedJIT
     * json_encode still cannot export those runtime tables (peer #27130 / #27546 / #27072).
     */
    private static function tryCompileTimeArrayFromArrayColumn(
        Block $block,
        Operand $operand
    ): ?VmVariable {
        $slot = $block->getVarSlot($operand, true);
        $slot = self::followAssignSourceSlot($block, $slot);
        $args = self::literalArgsForFuncCallResult($block, $slot, 'array_column');
        if (null === $args || \count($args) < 2 || \count($args) > 3) {
            return null;
        }
        if (VmVariable::TYPE_ARRAY !== $args[0]->type) {
            return null;
        }
        $ht = $args[0]->toArray();
        $column = $args[1];
        $indexKey = 3 === \count($args) ? $args[2] : null;

        $columnField = null;
        if (VmVariable::TYPE_NULL !== $column->type) {
            try {
                $columnField = VmArrayColumnArg::requireStrIntArg(
                    $column,
                    'array_column',
                    1,
                    'column_key'
                );
            } catch (\Throwable) {
                return null;
            }
        }
        $indexField = null;
        if (null !== $indexKey && VmVariable::TYPE_NULL !== $indexKey->type) {
            try {
                $indexField = VmArrayColumnArg::requireStrIntArg(
                    $indexKey,
                    'array_column',
                    2,
                    'index_key'
                );
            } catch (\Throwable) {
                return null;
            }
        }

        if (null === $columnField) {
            $out = null === $indexField
                ? ArrayColumnJitHelper::columnNull($ht)
                : ArrayColumnJitHelper::columnNullWithIndex($ht, $indexField);
        } elseif (null !== $indexField) {
            $out = ArrayColumnJitHelper::columnWithKeyAndIndex($ht, $columnField, $indexField);
        } else {
            $out = ArrayColumnJitHelper::columnWithKey($ht, $columnField);
        }
        $var = new VmVariable();
        $var->array($out);

        return $var;
    }

    /**
     * Resolve `json_encode(array_fill(…))` when start/count/value are compile-time (#27073).
     *
     * Uses {@see ArrayFillJitHelper::fillCopy()} SSOT (php-src array.c). Call-site
     * {@see \PHPCompiler\JIT\HashTableFillLlvm} builds a dim/count/foreach-green HT; NestedJIT
     * json_encode still cannot export those runtime tables (peer #27132 / #27072).
     */
    private static function tryCompileTimeArrayFromArrayFill(
        Block $block,
        Operand $operand
    ): ?VmVariable {
        $slot = $block->getVarSlot($operand, true);
        $slot = self::followAssignSourceSlot($block, $slot);
        $args = self::literalArgsForFuncCallResult($block, $slot, 'array_fill');
        if (null === $args || 3 !== \count($args)) {
            return null;
        }
        if (VmVariable::TYPE_INTEGER !== $args[0]->type
            || VmVariable::TYPE_INTEGER !== $args[1]->type) {
            return null;
        }
        $start = $args[0]->toInt();
        $count = $args[1]->toInt();
        if ($count < 0) {
            // Leave negative count to runtime ValueError (php-src php_array_fill).
            return null;
        }
        $filled = ArrayFillJitHelper::fillCopy($start, $count, $args[2]);
        $var = new VmVariable();
        $var->array($filled);

        return $var;
    }

    /**
     * Resolve `json_encode(array_fill_keys(…))` when keys/value are compile-time (#27127).
     *
     * Uses {@see ArrayFillKeysJitHelper::fillKeysCopy()} / {@see VmArray::fillKeys()} SSOT
     * (php-src array.c). Call-site {@see \PHPCompiler\JIT\HashTableFillKeysLlvm} builds a
     * dim/count-green HT; NestedJIT json_encode still cannot export runtime string-key
     * tables (peer #27132 / #27073 / #27072).
     */
    private static function tryCompileTimeArrayFromArrayFillKeys(
        Block $block,
        Operand $operand
    ): ?VmVariable {
        $slot = $block->getVarSlot($operand, true);
        $slot = self::followAssignSourceSlot($block, $slot);
        $args = self::literalArgsForFuncCallResult($block, $slot, 'array_fill_keys');
        if (null === $args || 2 !== \count($args)) {
            return null;
        }
        if (VmVariable::TYPE_ARRAY !== $args[0]->type) {
            return null;
        }
        $filled = ArrayFillKeysJitHelper::fillKeysCopy($args[0]->toArray(), $args[1]);
        $var = new VmVariable();
        $var->array($filled);

        return $var;
    }

    /**
     * Resolve `json_encode(array_change_key_case(…))` when args are compile-time (#27183).
     *
     * Uses VM {@see VmArray::changeKeyCase()} SSOT (php-src array.c). Call-site
     * {@see \PHPCompiler\JIT\HashTableChangeKeyCaseLlvm} builds a dim/count/isset-green HT;
     * NestedJIT json_encode still cannot export runtime string-key tables (peer #27072 / #27127).
     */
    private static function tryCompileTimeArrayFromArrayChangeKeyCase(
        Block $block,
        Operand $operand
    ): ?VmVariable {
        $slot = $block->getVarSlot($operand, true);
        $slot = self::followAssignSourceSlot($block, $slot);
        $args = self::literalArgsForFuncCallResult($block, $slot, 'array_change_key_case');
        if (null === $args || \count($args) < 1 || \count($args) > 2) {
            return null;
        }
        if (VmVariable::TYPE_ARRAY !== $args[0]->type) {
            return null;
        }
        $case = StdlibConstants::CASE_LOWER;
        if (2 === \count($args)) {
            if (VmVariable::TYPE_INTEGER !== $args[1]->type) {
                return null;
            }
            $case = $args[1]->toInt();
        }
        $changed = VmArray::changeKeyCase($args[0]->toArray(), $case);
        $var = new VmVariable();
        $var->array($changed);

        return $var;
    }

    /**
     * Resolve `json_encode(parse_url(…))` when URL(/component) are compile-time (#27078).
     *
     * Uses {@see VmString::parseUrl()} SSOT (php-src url.c). parse_url call-site LLVM
     * builds a correct foreach/dim HT; NestedJIT json_encode still exports string keys
     * as empty / zeros without this fold (peer #27072 / #26977).
     */
    private static function tryCompileTimeArrayFromParseUrl(
        Block $block,
        Operand $operand
    ): ?VmVariable {
        $parsed = self::evalCompileTimeParseUrl($block, $operand);
        if (null === $parsed || false === $parsed || !\is_array($parsed)) {
            return null;
        }
        $ht = new \PHPCompiler\VM\HashTable();
        foreach ($parsed as $key => $value) {
            $v = new VmVariable();
            if (\is_int($value)) {
                $v->int($value);
            } else {
                $v->string((string) $value);
            }
            $ht->add((string) $key, $v);
        }
        $var = new VmVariable();
        $var->array($ht);

        return $var;
    }

    /**
     * When parse_url constexpr-fails (false), json_encode soft-encodes boolean false.
     *
     * @return Value|null
     */
    private static function tryEncodeParseUrlFalse(
        Context $context,
        Block $block,
        Operand $operand,
        int $flags
    ): ?Value {
        $parsed = self::evalCompileTimeParseUrl($block, $operand);
        if (false !== $parsed) {
            return null;
        }
        $encoded = VmJsonFormat::encodeExported(false, $flags);
        if (false === $encoded) {
            return self::emitFalse($context);
        }

        return $context->builder->load($context->constantStringFromString($encoded));
    }

    /**
     * @return array<string, int|string>|string|int|null|false
     *         null = not a foldable parse_url result
     */
    private static function evalCompileTimeParseUrl(Block $block, Operand $operand): array|string|int|null|false
    {
        $slot = $block->getVarSlot($operand, true);
        $slot = self::followAssignSourceSlot($block, $slot);
        $args = self::literalArgsForFuncCallResult($block, $slot, 'parse_url');
        if (null === $args || [] === $args || \count($args) > 2) {
            return null;
        }
        if (VmVariable::TYPE_STRING !== $args[0]->type) {
            return null;
        }
        $url = $args[0]->toString();
        $component = -1;
        if (2 === \count($args)) {
            if (VmVariable::TYPE_INTEGER !== $args[1]->type) {
                return null;
            }
            $component = VmParseUrl::normalizeRawComponentInt($args[1]->toInt());
        }

        return VmString::parseUrl($url, $component);
    }

    private static int $lastPregSplitFlags = 0;

    /**
     * When preg_split constexpr-fails (false), json_encode soft-encodes boolean false.
     *
     * @return Value|null
     */
    private static function tryEncodePregSplitFalse(
        Context $context,
        Block $block,
        Operand $operand,
        int $flags
    ): ?Value {
        $parts = self::evalCompileTimePregSplit($block, $operand);
        if (false !== $parts) {
            return null;
        }
        // json_encode(false) with default flags → "false"
        $encoded = VmJsonFormat::encodeExported(false, $flags);
        if (false === $encoded) {
            return self::emitFalse($context);
        }

        return $context->builder->load($context->constantStringFromString($encoded));
    }

    /**
     * @return list<string>|list<array{0:string,1:int}>|false|null
     *         null = not a foldable preg_split result; false = PCRE error
     */
    private static function evalCompileTimePregSplit(Block $block, Operand $operand): array|false|null
    {
        $slot = $block->getVarSlot($operand, true);
        $slot = self::followAssignSourceSlot($block, $slot);
        $args = self::literalArgsForFuncCallResult($block, $slot, 'preg_split');
        if (null === $args) {
            return null;
        }
        $argc = \count($args);
        if ($argc < 2 || $argc > 4) {
            return null;
        }
        if (VmVariable::TYPE_STRING !== $args[0]->type || VmVariable::TYPE_STRING !== $args[1]->type) {
            return null;
        }
        $pattern = $args[0]->toString();
        $subject = $args[1]->toString();
        $limit = -1;
        $flags = 0;
        if ($argc >= 3) {
            if (VmVariable::TYPE_INTEGER !== $args[2]->type) {
                return null;
            }
            $limit = $args[2]->toInt();
        }
        if (4 === $argc) {
            if (VmVariable::TYPE_INTEGER !== $args[3]->type) {
                return null;
            }
            $flags = $args[3]->toInt();
        }
        self::$lastPregSplitFlags = $flags;

        return VmPreg::pregSplit($pattern, $subject, $limit, $flags);
    }

    private static function followAssignSourceSlot(Block $block, int $slot): int
    {
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_ASSIGN === $op->type && $op->arg2 === $slot && null !== $op->arg3) {
                return self::followAssignSourceSlot($block, (int) $op->arg3);
            }
        }

        return $slot;
    }

    /**
     * @return list<VmVariable>|null
     */
    private static function literalArgsForFuncCallResult(Block $block, int $resultSlot, string $callee): ?array
    {
        $ops = $block->opCodes;
        $execIdx = null;
        foreach ($ops as $i => $op) {
            if (OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type && $op->arg1 === $resultSlot) {
                $execIdx = $i;
                break;
            }
        }
        if (null === $execIdx) {
            return null;
        }
        $initIdx = null;
        for ($i = $execIdx - 1; $i >= 0; --$i) {
            if (OpCode::TYPE_FUNCCALL_INIT === $ops[$i]->type) {
                $initIdx = $i;
                break;
            }
            if (
                OpCode::TYPE_FUNCCALL_EXEC_RETURN === $ops[$i]->type
                || OpCode::TYPE_FUNCCALL_EXEC_NORETURN === $ops[$i]->type
            ) {
                return null;
            }
        }
        if (null === $initIdx) {
            return null;
        }
        $nameSlot = $ops[$initIdx]->arg1;
        if (null === $nameSlot || !isset($block->constants[$nameSlot])) {
            return null;
        }
        $nameVar = $block->constants[$nameSlot];
        if (VmVariable::TYPE_STRING !== $nameVar->type) {
            return null;
        }
        if (\strtolower($nameVar->toString()) !== \strtolower($callee)) {
            return null;
        }
        $args = [];
        for ($i = $initIdx + 1; $i < $execIdx; ++$i) {
            $op = $ops[$i];
            if (OpCode::TYPE_ARG_SEND !== $op->type) {
                continue;
            }
            if (null === $op->arg1) {
                return null;
            }
            if (isset($block->constants[$op->arg1])) {
                $copy = new VmVariable();
                $copy->copyFrom($block->constants[$op->arg1]);
                $args[] = $copy;
                continue;
            }
            // Core int constants (CASE_LOWER / CASE_UPPER / …) via CONST_FETCH (#27183).
            $foldedInt = self::tryCompileTimeIntFromSlot($block, (int) $op->arg1);
            if (null !== $foldedInt) {
                $copy = new VmVariable();
                $copy->int($foldedInt);
                $args[] = $copy;
                continue;
            }
            // Concat / assign of string literals (parse_url URL from `"a"."b"`, #27078).
            $foldedString = self::tryCompileTimeStringFromSlot($block, (int) $op->arg1);
            if (null !== $foldedString) {
                $copy = new VmVariable();
                $copy->string($foldedString);
                $args[] = $copy;
                continue;
            }
            // INIT_ARRAY slots (inline array literals) — same recovery as json_encode(lit) (#26977).
            $argOperand = $block->getOperand((int) $op->arg1);
            if (null === $argOperand) {
                return null;
            }
            $arr = CallUnpackHelper::tryCompileTimeArrayFromOperand($block, $argOperand);
            if (null === $arr) {
                return null;
            }
            $args[] = $arr;
        }

        return $args;
    }

    /**
     * Fold CONST_FETCH of known core int constants (CASE_LOWER / PATHINFO_* / …).
     *
     * @param array<int, true> $visited
     */
    private static function tryCompileTimeIntFromSlot(Block $block, int $slot, array $visited = []): ?int
    {
        if (isset($visited[$slot])) {
            return null;
        }
        $visited[$slot] = true;
        if (isset($block->constants[$slot]) && VmVariable::TYPE_INTEGER === $block->constants[$slot]->type) {
            return $block->constants[$slot]->toInt();
        }
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_CONST_FETCH !== $op->type || $op->arg1 !== $slot) {
                continue;
            }
            $nameOp = null !== $op->arg3 ? $block->getOperand($op->arg3) : $block->getOperand($op->arg2);
            if (!$nameOp instanceof Literal) {
                return null;
            }
            $lookup = \strtolower((string) $nameOp->value);

            return StdlibConstants::CORE_INT_BY_NAME[$lookup] ?? null;
        }
        foreach ($block->opCodes as $prior) {
            if (OpCode::TYPE_ASSIGN === $prior->type && $prior->arg2 === $slot && null !== $prior->arg3) {
                $resolved = self::tryCompileTimeIntFromSlot($block, (int) $prior->arg3, $visited);
                if (null !== $resolved) {
                    return $resolved;
                }
            }
        }
        foreach ($block->parents as $parent) {
            if (!$parent instanceof Block) {
                continue;
            }
            $resolved = self::tryCompileTimeIntFromSlot($parent, $slot, $visited);
            if (null !== $resolved) {
                return $resolved;
            }
        }

        return null;
    }

    /**
     * Fold CONCAT / ASSIGN chains of string constants (mirrors JIT resolveJitCompileTimeStringSlot).
     *
     * @param array<int, true> $visited
     */
    private static function tryCompileTimeStringFromSlot(Block $block, int $slot, array $visited = []): ?string
    {
        if (isset($visited[$slot])) {
            return null;
        }
        $visited[$slot] = true;
        if (isset($block->constants[$slot]) && VmVariable::TYPE_STRING === $block->constants[$slot]->type) {
            return $block->constants[$slot]->toString();
        }
        foreach ($block->opCodes as $prior) {
            if (OpCode::TYPE_CONCAT === $prior->type && $prior->arg1 === $slot) {
                $left = self::tryCompileTimeStringFromSlot($block, (int) $prior->arg2, $visited);
                $right = self::tryCompileTimeStringFromSlot($block, (int) $prior->arg3, $visited);
                if (null !== $left && null !== $right) {
                    return $left.$right;
                }
            }
            if (OpCode::TYPE_ASSIGN === $prior->type && $prior->arg2 === $slot && null !== $prior->arg3) {
                $resolved = self::tryCompileTimeStringFromSlot($block, (int) $prior->arg3, $visited);
                if (null !== $resolved) {
                    return $resolved;
                }
            }
        }
        foreach ($block->parents as $parent) {
            if (!$parent instanceof Block) {
                continue;
            }
            $resolved = self::tryCompileTimeStringFromSlot($parent, $slot, $visited);
            if (null !== $resolved) {
                return $resolved;
            }
        }

        return null;
    }
}
