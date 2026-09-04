<?php

declare(strict_types=1);

namespace PHPCompiler\ext\simplexml;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\UserScriptAotEnv;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * LLVM lowering for simplexml_load_string() — user-script AOT (#26863, #36382).
 *
 * php-src: ext/simplexml/simplexml.c — PHP_FUNCTION(simplexml_load_string)
 *
 * Compile-time XML literals fold via {@see JitSimpleXmlUserScript::tryLoadString}.
 * Non-literal $data (Slim BodyParsingMiddleware request body) cannot NestedJIT
 * VmSimpleXml under thin user-script AOT: ObjectEntry layout SIGSEGVs on
 * get_class/(string), and helper link pulls WeakRef→phpc_base_convert. Soft-false
 * matches php-src's failure polarity so Composer graphs compile; the middleware
 * maps false → null. Full runtime SXE is a follow-up.
 */
final class JitSimpleXmlLoadString
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 1) {
            throw new \ArgumentCountError('simplexml_load_string() expects at least 1 argument, 0 given');
        }
        if (UserScriptAotEnv::isActive()) {
            $us = JitSimpleXmlUserScript::tryLoadString($context, ...$args);
            if (null !== $us) {
                return $us;
            }

            return self::softFalseForNonLiteral($context, ...$args);
        }

        throw new \LogicException('simplexml_load_string() is not JIT-lowered in this compiler build');
    }

    /**
     * Lower $data for Z_PARAM_STR soft-null DEP, then return false (#36382).
     */
    private static function softFalseForNonLiteral(Context $context, JITVariable ...$args): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'simplexml_load_string_soft_false');
        if (isset($args[1]) && JITVariable::TYPE_NULL !== $args[1]->type) {
            $classLit = JitStringBuiltinArg::compileTimeLiteral($args[1]) ?? $args[1]->compileTimeString;
            if (null === $classLit || ('' !== $classLit && 0 !== strcasecmp($classLit, 'SimpleXMLElement'))) {
                throw new \LogicException(
                    'simplexml_load_string() AOT runtime path requires default class_name SimpleXMLElement (#36382)'
                );
            }
        }
        // Side-effect: null $data soft-deprecation under 8.4 (Z_PARAM_STR).
        JitStringBuiltinArg::lowerTrimFamilyString(
            $context,
            $args[0],
            'simplexml_load_string',
            0,
            'data'
        );
        $slot = JitValueBox::alloc($context);
        JitValueBox::writeBool(
            $context,
            $slot,
            $context->getTypeFromString('int1')->constInt(0, false)
        );

        return JitValueBox::normalizeValuePtr($context, $slot);
    }
}
