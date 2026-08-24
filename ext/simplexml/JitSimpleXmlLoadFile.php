<?php

declare(strict_types=1);

namespace PHPCompiler\ext\simplexml;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\UserScriptAotEnv;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * LLVM lowering for simplexml_load_file() — user-script AOT (#34454).
 *
 * php-src: ext/simplexml/simplexml.c — PHP_FUNCTION(simplexml_load_file)
 */
final class JitSimpleXmlLoadFile
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 1) {
            throw new \ArgumentCountError('simplexml_load_file() expects at least 1 argument, 0 given');
        }
        if (UserScriptAotEnv::isActive()) {
            $us = JitSimpleXmlUserScript::tryLoadFile($context, ...$args);
            if (null !== $us) {
                return $us;
            }
            throw new \LogicException(
                'simplexml_load_file() user-script AOT requires a compile-time path literal (#34454)'
            );
        }
        throw new \LogicException('simplexml_load_file() is not JIT-lowered in this compiler build');
    }
}
