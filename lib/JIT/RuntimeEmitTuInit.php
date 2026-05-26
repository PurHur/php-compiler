<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

require_once __DIR__.'/RuntimeInitCompiler.php';
require_once __DIR__.'/RuntimeInitVmContext.php';

use PHPLLVM\Value;

/**
 * M3 emit-helper Runtime init after allocateEmitTuShell (#2550, #2552).
 *
 * C-floor initVmContext first; initParsePipeline / initCompiler / loadCoreModules via spine symbols.
 */
final class RuntimeEmitTuInit
{
    public static function emitInitSequence(Context $context, Value $runtime, Value $mode): void
    {
        $object = $context->type->object;
        $modeSlot = $object->propertyFetch($runtime, 'PHPCompiler\\Runtime', 'mode');
        $modeVar = new Variable($context, Variable::TYPE_NATIVE_LONG, Variable::KIND_VALUE, $mode);
        $object->propertyStore($modeSlot->objectPropertySlot, $modeVar, Variable::TYPE_NATIVE_LONG);

        foreach (['initparsepipeline'] as $methodLc) {
            $context->builder->call(
                BootstrapCompileSmokeM3Emit::runtimeSpineFn($context, $methodLc, 'void', ['__object__*']),
                $runtime
            );
        }
        RuntimeInitCompiler::emit($context, $object, $runtime);
        RuntimeInitVmContext::emit($context, $object, $runtime);

        foreach (['loadcoremodules'] as $methodLc) {
            $context->builder->call(
                BootstrapCompileSmokeM3Emit::runtimeSpineFn($context, $methodLc, 'void', ['__object__*']),
                $runtime
            );
        }
    }
}
