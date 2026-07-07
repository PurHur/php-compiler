<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value;

/**
 * JIT/AOT bridge for ext/dom instance methods via DomInstanceMethodJitHelper (#17130).
 *
 * php-src: ext/dom/php_dom.c — DOM*::method handlers
 */
final class DomInstanceMethodRuntime
{
    public const ABI = '__phpc_jit_dom_instance_method';

    private const HELPER_PATH = '/ext/dom/VmDomInstanceInvoke.php';

    private const INVOKE_HELPER = 'PHPCompiler\\ext\\dom\\VmDomInstanceInvoke::invokeArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::INVOKE_HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::ABI, $probe);

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        $valuePtr = $context->getTypeFromString('__value__*');
        $strPtr = $context->getTypeFromString('__string__*');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI,
            'dom_instance_method_bridge_entry',
            [$valuePtr, $strPtr, $valuePtr],
            $valuePtr,
            self::INVOKE_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#17130'
        );

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    public static function invoke(
        Context $context,
        Value $receiverBox,
        string $methodLc,
        Value $argsBox
    ): Value {
        self::ensureLinked($context);
        $methodConst = $context->builder->load($context->constantStringFromString($methodLc));

        return $context->builder->call(
            $context->lookupFunction(self::ABI),
            $receiverBox,
            $methodConst,
            $argsBox
        );
    }
}
