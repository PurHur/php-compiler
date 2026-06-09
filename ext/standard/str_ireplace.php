<?php

declare(strict_types=1);

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

/** str_ireplace() — case-insensitive str_replace for strings (VM + JIT/AOT; libc strcasestr in JIT). */
final class str_ireplace extends Internal
{
    public function __construct()
    {
        parent::__construct('str_ireplace');
    }

    public function execute(Frame $frame): void
    {
        if (3 !== \count($frame->calledArgs)) {
            throw new \LogicException('str_ireplace() requires exactly three arguments in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $search = self::coerceStringReplaceArg($frame->calledArgs[0], 'str_ireplace', 0, 'search');
        $replace = self::coerceStringReplaceArg($frame->calledArgs[1], 'str_ireplace', 1, 'replace');
        $subjectVar = VmPreg::requireStringOrArraySubject(
            $frame->calledArgs[2],
            'str_ireplace',
            2,
            'subject'
        );

        if (Variable::TYPE_STRING === $subjectVar->type) {
            $frame->returnVar->string(VmString::strIreplace($search, $replace, $subjectVar->toString()));

            return;
        }

        $ht = new HashTable();
        foreach ($subjectVar->toArray()->iterateKeyed(true) as [$key, $value]) {
            if (Variable::TYPE_STRING !== $value->type) {
                throw new \LogicException(
                    'str_ireplace() array subject values must be strings in this compiler build'
                );
            }
            $replaced = VmString::strIreplace($search, $replace, $value->toString());
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

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (3 !== \count($args)) {
            throw new \LogicException('str_ireplace() requires exactly three arguments in this compiler build');
        }

        $search = JitStringArg::lower($context, $args[0], 'str_ireplace() search');
        $replace = JitStringArg::lower($context, $args[1], 'str_ireplace() replace');
        JitPregSubject::requireStringOrArray($context, $args[2], 'str_ireplace', 2, 'subject');
        if (JITVariable::TYPE_STRING === $args[2]->type) {
            return JitStrIreplace::replace(
                $context,
                $search,
                $replace,
                JitStringArg::lower($context, $args[2], 'str_ireplace() subject')
            );
        }

        return JitStrReplaceArray::invoke($context, $search, $replace, $args[2], true);
    }

    /**
     * php-src Z_PARAM_STR on str_ireplace() search/replace — enum cases TypeError (#5889).
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
