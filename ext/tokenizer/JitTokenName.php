<?php

declare(strict_types=1);

namespace PHPCompiler\ext\tokenizer;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\InternalStrictArg as JitInternalStrictArg;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM lowering for token_name() (#3171, #7254, #27278, #31407).
 *
 * Compile-time T_* / ConstantInt folding stays as an optional fast path.
 * Runtime ints (e.g. token_get_all()[$i][0]) use an inline id→name select-walk
 * (peer: {@see \PHPCompiler\JIT\Call\PhpTokenGetTokenName}).
 *
 * php-src: ext/tokenizer/tokenizer.c — PHP_FUNCTION(token_name)
 */
final class JitTokenName
{
    public static function lower(Context $context, JITVariable $arg): Value
    {
        // Caller strict_types: reject null before coerce-to-0 (#31407; peer image_type_to_mime_type).
        JitInternalStrictArg::requireInt($context, $arg, 'token_name', 'id', 1);

        $folded = self::tryResolveCompileTimeName($context, $arg);
        if (null !== $folded) {
            return $context->builder->load($context->constantStringFromString($folded));
        }

        $tokenId = JitLongArg::lower($context, $arg, 'token_name() argument');

        return self::emitNameSelectWalk($context, $tokenId);
    }

    private static function tryResolveCompileTimeName(Context $context, JITVariable $arg): ?string
    {
        if (null !== $arg->compileTimeConstantName) {
            $constants = TokenConstants::registeredConstants();
            if (isset($constants[$arg->compileTimeConstantName])) {
                $resolved = TokenConstants::nameForId($constants[$arg->compileTimeConstantName]);

                return null !== $resolved ? $resolved : 'UNKNOWN';
            }
        }
        if (JITVariable::TYPE_NATIVE_LONG === $arg->type && JITVariable::KIND_VALUE === $arg->kind) {
            $lib = $context->llvm->lib;
            if (null !== $lib->LLVMIsAConstantInt($arg->value->value)) {
                $id = (int) $lib->LLVMConstIntGetZExtValue($arg->value->value);
                $name = TokenConstants::nameForId($id);

                return null !== $name ? $name : 'UNKNOWN';
            }
        }

        return null;
    }

    /** @return Value {@see __string__*} */
    private static function emitNameSelectWalk(Context $context, Value $tokenId): Value
    {
        $result = $context->builder->load($context->constantStringFromString('UNKNOWN'));
        // Prefer the committed lexer map so AOT ids from LanguageScanner / token_get_all match.
        foreach (TokenConstantsData::idToName() as $id => $name) {
            if ('TOKEN_PARSE' === $name) {
                continue;
            }
            $expected = $context->constantFromInteger((int) $id, 'int64');
            $isId = $context->builder->icmp(Builder::INT_EQ, $tokenId, $expected);
            $candidate = $context->builder->load($context->constantStringFromString((string) $name));
            $result = $context->builder->select($isId, $candidate, $result);
        }

        return $result;
    }
}
