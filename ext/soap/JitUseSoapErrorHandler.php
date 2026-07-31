<?php

declare(strict_types=1);

namespace PHPCompiler\ext\soap;

use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitBoolArg;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * use_soap_error_handler() JIT — toggle SOAP_GLOBAL flag (#26168 / #20267).
 */
final class JitUseSoapErrorHandler
{
    private const ABI = '__soap_use_soap_error_handler';

    private const HELPER_PATH = '/ext/soap/UseSoapErrorHandlerJitHelper.php';

    private const HELPER = 'PHPCompiler\\ext\\soap\\UseSoapErrorHandlerJitHelper::toggle';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::HELPER,
    ];

    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc > 1) {
            TypeErrorRaise::ensureLinked($context);
            TypeErrorRaise::emitArgumentCountError(
                $context,
                'use_soap_error_handler() expects at most 1 argument, '.$argc.' given'
            );

            return $context->constantFromBool(false);
        }

        $enable = $context->constantFromBool(true);
        if (1 === $argc) {
            $enable = JitBoolArg::lowerCoerce(
                $context,
                $args[0],
                'use_soap_error_handler(): Argument #1 ($enable)'
            );
        }

        self::ensureLinked($context);
        $i32 = $context->getTypeFromString('int32');
        $enableI32 = $context->builder->zExt($enable, $i32);
        $prev = $context->builder->call(
            $context->lookupFunction(self::ABI),
            $enableI32
        );

        return $context->builder->icmp(
            Builder::INT_NE,
            $prev,
            $i32->constInt(0, false)
        );
    }

    private static function ensureLinked(Context $context): void
    {
        $i32 = $context->getTypeFromString('int32');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI,
            'use_soap_error_handler_bridge_entry',
            [$i32],
            $i32,
            self::HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#26168'
        );
    }
}
