<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPLLVM\Value;

/**
 * AOT argc for DOMCharacterData mutators — compile-time fold drops surplus args (#31091).
 *
 * php-src: ext/dom/characterdata.c ZEND_PARSE_PARAMETERS_EX
 */
final class DomCharacterDataJitArgc
{
    public static function rejectUnlessExactUserArgCount(
        Context $context,
        string $function,
        int $expected,
        JITVariable ...$args
    ): ?Value {
        $given = $context->callSiteOutgoingUserArgCount ?? max(0, \count($args) - 1);
        if ($given === $expected) {
            return null;
        }
        $message = DomClassMethod::exactUserArgCountMessage($function, $expected, $given);
        ExceptionBridge::emitArgumentCountErrorAndAbort($context, $message);
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_characterdata_ace_cont');

        return VmClassMethod::jitArgcDummyReturn($context);
    }
}
