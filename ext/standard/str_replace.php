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
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\RuntimeStrictness;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\HashTable;
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
        if (3 !== \count($frame->calledArgs)) {
            throw new \LogicException('str_replace() requires exactly three arguments in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $search = self::coerceStringReplaceArg($frame->calledArgs[0], 'str_replace', 0, 'search');
        $replace = self::coerceStringReplaceArg($frame->calledArgs[1], 'str_replace', 1, 'replace');
        $subjectVar = VmPreg::requireStringOrArraySubject(
            $frame->calledArgs[2],
            'str_replace',
            2,
            'subject'
        );

        if (Variable::TYPE_STRING === $subjectVar->type) {
            $frame->returnVar->string(VmString::strReplace($search, $replace, $subjectVar->toString()));

            return;
        }

        $ht = new HashTable();
        foreach ($subjectVar->toArray()->iterateKeyed(true) as [$key, $value]) {
            if (Variable::TYPE_STRING !== $value->type) {
                throw new \LogicException(
                    'str_replace() array subject values must be strings in this compiler build'
                );
            }
            $replaced = VmString::strReplace($search, $replace, $value->toString());
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
        $frame->returnVar->array($ht);
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        if (3 !== \count($args)) {
            throw new \LogicException('str_replace() requires exactly three arguments in this compiler build');
        }

        $search = JitStringArg::lower($context, $args[0], 'str_replace() search');
        $replace = JitStringArg::lower($context, $args[1], 'str_replace() replace');
        JitPregSubject::requireStringOrArray($context, $args[2], 'str_replace', 2, 'subject');
        if (JITVariable::TYPE_STRING === $args[2]->type) {
            return JitStrReplace::replace(
                $context,
                $search,
                $replace,
                JitStringArg::lower($context, $args[2], 'str_replace() subject')
            );
        }

        return JitStrReplaceArray::invoke($context, $search, $replace, $args[2], false);
    }

    /**
     * php-src Z_PARAM_STR on str_replace() search/replace — enum cases TypeError (#5889).
     */
    private static function coerceStringReplaceArg(
        Variable $var,
        string $function,
        int $argIndex,
        string $paramName
    ): string {
        $var = $var->resolveIndirect();
        if (RuntimeStrictness::enforceStringBuiltinParityGuards() && EnumCaseSupport::isEnumCaseVariable($var)) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($%s) must be of type array|string, %s given',
                $function,
                $argIndex + 1,
                $paramName,
                EnumCaseSupport::typeNameForVariable($var)
            ));
        }
        if (Variable::TYPE_STRING !== $var->type) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($%s) must be of type array|string, %s given',
                $function,
                $argIndex + 1,
                $paramName,
                self::replaceArgTypeLabel($var)
            ));
        }

        return $var->toString();
    }

    private static function replaceArgTypeLabel(Variable $var): string
    {
        if (EnumCaseSupport::isEnumCaseVariable($var)) {
            return EnumCaseSupport::typeNameForVariable($var);
        }

        return match ($var->type) {
            Variable::TYPE_INTEGER => 'int',
            Variable::TYPE_FLOAT => 'float',
            Variable::TYPE_BOOLEAN => 'bool',
            Variable::TYPE_STRING => 'string',
            Variable::TYPE_NULL => 'null',
            Variable::TYPE_ARRAY => 'array',
            Variable::TYPE_OBJECT => $var->toObject()->class->name,
            default => 'mixed',
        };
    }
}
