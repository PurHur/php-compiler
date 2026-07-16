<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\dom\JitDomStandaloneAotInitKernel;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;

/**
 * Thin standalone AOT: register ext/dom classes on the allocated vmContext (#17391).
 */
final class DomStandaloneAotInitRuntime
{
    public const ABI_NAME = '__phpc_dom_standalone_aot_init';

    private const HELPER_PATH = '/ext/dom/DomStandaloneAotInitJitHelper.php';

    private const HELPER = 'PHPCompiler\\ext\\dom\\DomStandaloneAotInitJitHelper::registerDomExtensionClasses';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI_NAME);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::ABI_NAME, $probe);

            return;
        }

        if (JitDomStandaloneAotInitKernel::shouldUse($context)) {
            JitDomStandaloneAotInitKernel::ensureLinked($context);

            return;
        }

        $objPtr = $context->getTypeFromString('__object__*');
        $voidTy = $context->getTypeFromString('void');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_NAME,
            'dom_standalone_aot_init_bridge',
            [$objPtr],
            $voidTy,
            self::HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#17391'
        );
    }
}
