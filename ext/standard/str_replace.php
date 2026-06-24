<?php

declare(strict_types=1);

/**
 * This file is part of PHP-Compiler, a PHP CFG Compiler for PHP code
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\RuntimeStrictness;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\InternalStrictArg;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * str_replace() with string search, replace, and subject (subset of PHP; LLVM JIT/AOT).
 * Array $subject: VM + JIT/AOT (#4056, ext/standard/string.c php_str_replace_array).
 */
final class str_replace extends Internal
{
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 3 || $argc > 4) {
            throw new \LogicException('str_replace() requires 3 or 4 arguments in this compiler build');
        }
        $hasCount = $argc >= 4;
        $search = self::coerceStringReplaceArg($frame, $frame->calledArgs[0], 'str_replace', 0, 'search');
        $replace = self::coerceStringReplaceArg($frame, $frame->calledArgs[1], 'str_replace', 1, 'replace');
        $subjectVar = VmPreg::requireStringOrArraySubject(
            $frame->calledArgs[2],
            'str_replace',
            2,
            'subject'
        );

        if (Variable::TYPE_STRING === $subjectVar->type) {
            $count = 0;
            $result = VmString::strReplace(
                $search,
                $replace,
                $subjectVar->toString(),
                $count
            );
            if ($hasCount) {
                $frame->calledArgs[3]->resolveIndirect()->int($count);
            }
            if (null !== $frame->returnVar) {
                $frame->returnVar->string($result);
            }

            return;
        }

        $ht = new HashTable();
        $totalCount = 0;
        foreach ($subjectVar->toArray()->iterateKeyed(true) as [$key, $value]) {
            if (Variable::TYPE_STRING !== $value->type) {
                throw new \LogicException(
                    'str_replace() array subject values must be strings in this compiler build'
                );
            }
            $elemCount = 0;
            $replaced = VmString::strReplace($search, $replace, $value->toString(), $elemCount);
            $totalCount += $elemCount;
            $keyVar = new Variable();
            if (Variable::TYPE_INTEGER === $key->type) {
                $keyVar->int($key->toInt());
            } else {
                $keyVar->string($key->toString());
            }
            $outVal = new Variable();
            $outVal->string($replaced);
            array_map::appendKeyedCopy($ht, $keyVar, $outVal);
        }
        if ($hasCount) {
            $frame->calledArgs[3]->resolveIndirect()->int($totalCount);
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->array($ht);
        }
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        $argc = \count($args);
        if ($argc < 3 || $argc > 4) {
            throw new \LogicException('str_replace() requires 3 or 4 arguments in this compiler build');
        }

        $search = JitStringBuiltinArg::lower($context, $args[0], 'str_replace', 0, 'search', 'array|string');
        $replace = JitStringBuiltinArg::lower($context, $args[1], 'str_replace', 1, 'replace', 'array|string');
        JitPregSubject::requireStringOrArray($context, $args[2], 'str_replace', 2, 'subject');
        $countSlot = self::jitCountSlot($context, 4 === $argc);
        if (JITVariable::TYPE_STRING === $args[2]->type) {
            $result = JitStrReplace::replace(
                $context,
                $search,
                $replace,
                JitStringArg::lower($context, $args[2], 'str_replace() subject'),
                false,
                $countSlot
            );
        } else {
            $result = JitStrReplaceArray::invoke($context, $search, $replace, $args[2], false, $countSlot);
        }
        if (4 === $argc) {
            JitValueBox::writeLong(
                $context,
                JitValueBox::valuePtrFromVariable($context, $args[3]),
                $context->builder->load($countSlot)
            );
        }

        return $result;
    }

    private static function jitCountSlot(Context $context, bool $track): ?Value
    {
        if (!$track) {
            return null;
        }
        $i64 = $context->getTypeFromString('int64');
        $slot = BasicBlockHelper::entryAlloca($context, $i64);
        $context->builder->store($i64->constInt(0, false), $slot);

        return $slot;
    }

    /**
     * php-src Z_PARAM_STR on str_replace() search/replace — null coerces outside strict_types (#11014, ext/standard/string.c).
     */
    private static function coerceStringReplaceArg(
        Frame $frame,
        Variable $var,
        string $function,
        int $argIndex,
        string $paramName
    ): string {
        $var = $var->resolveIndirect();
        if (InternalStrictArg::isCallerStrict($frame) && Variable::TYPE_NULL === $var->type) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($%s) must be of type array|string, null given',
                $function,
                $argIndex + 1,
                $paramName
            ));
        }
        if (RuntimeStrictness::enforceStringBuiltinParityGuards() && EnumCaseSupport::isEnumCaseVariable($var)) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($%s) must be of type array|string, %s given',
                $function,
                $argIndex + 1,
                $paramName,
                EnumCaseSupport::typeNameForVariable($var)
            ));
        }

        return VmString::coerceStringBuiltinArg($var, $function, $argIndex, $paramName);
    }
}
