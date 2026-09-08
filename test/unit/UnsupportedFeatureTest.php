<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\Lint\UnsupportedFeature;
use PHPCompiler\Lint\UnsupportedRegistry;
use PHPUnit\Framework\TestCase;

/**
 * @see https://github.com/PurHur/php-compiler/issues/36396
 */
final class UnsupportedFeatureTest extends TestCase
{
    public function testFormatMatchesIssueContract(): void
    {
        $msg = UnsupportedFeature::format(
            'range() start/end that are not int, float, or single-char string',
            'docs/capabilities.md#range',
            4258,
            'use integer, float, or single-character string bounds'
        );
        $this->assertSame(
            'phpc: unsupported: range() start/end that are not int, float, or single-char string'
            .' (docs/capabilities.md#range, #4258) — use integer, float, or single-character string bounds',
            $msg
        );
    }

    public function testEveryCataloguedFeatureHasMatrixIssueAndAlternative(): void
    {
        $features = UnsupportedRegistry::knownFeatures();
        $this->assertNotEmpty($features);
        foreach ($features as $id => $row) {
            $this->assertNotSame('', trim($row['feature']), $id.' feature');
            $this->assertNotSame('', trim($row['matrixRow']), $id.' matrixRow');
            $this->assertGreaterThan(0, $row['issue'], $id.' issue');
            $this->assertNotSame('', trim($row['alternative']), $id.' alternative');
            $formatted = UnsupportedFeature::format(
                $row['feature'],
                $row['matrixRow'],
                $row['issue'],
                $row['alternative']
            );
            $this->assertStringStartsWith('phpc: unsupported: ', $formatted, $id);
            $this->assertStringContainsString(', #'.$row['issue'].')', $formatted, $id);
            $this->assertStringContainsString($row['matrixRow'], $formatted, $id);
            $this->assertStringContainsString(' — ', $formatted, $id);
        }
    }

    public function testRaiseThrowsUnsupportedFeature(): void
    {
        try {
            UnsupportedFeature::raise('range-non-int-endpoints');
            $this->fail('expected UnsupportedFeature');
        } catch (UnsupportedFeature $e) {
            $this->assertSame(4258, $e->issue);
            $this->assertStringStartsWith('phpc: unsupported: ', $e->getMessage());
            $this->assertStringContainsString('docs/capabilities.md#range', $e->getMessage());
        }
    }

    public function testExplainForTryCatch(): void
    {
        $explain = UnsupportedRegistry::explainForKind('Stmt_TryCatch');
        $this->assertNotNull($explain);
        $this->assertStringContainsString('#57', $explain);
        $this->assertStringContainsString('phpc: unsupported:', $explain);
    }

    public function testErrorHandlerRejectionUsesCatalog(): void
    {
        $msg = \PHPCompiler\JIT\ErrorHandlerCallbackPolicy::jitRejectionMessage();
        $this->assertStringStartsWith('phpc: unsupported: ', $msg);
        $this->assertStringContainsString('#1379', $msg);
    }

    public function testLintExplainAppendsCatalogLineForTryCatch(): void
    {
        $issue = new \PHPCompiler\Lint\Issue(
            '/tmp/example.php',
            3,
            'Stmt_TryCatch',
            'Unsupported expression: Stmt_TryCatch',
            57
        );
        $out = $issue->formatExplain();
        $this->assertStringContainsString('unsupported Stmt_TryCatch', $out);
        $this->assertStringContainsString('phpc: unsupported:', $out);
        $this->assertStringContainsString('#57', $out);
    }

    public function testArrayMapAndRangeSitesUseCatalog(): void
    {
        $map = \PHPCompiler\JIT\ArrayMapCallbackPolicy::jitRejectionMessage();
        $this->assertStringStartsWith('phpc: unsupported: ', $map);
        $this->assertStringContainsString('docs/capabilities.md#array_map', $map);

        try {
            UnsupportedFeature::raise('range-non-int-step');
            $this->fail('expected UnsupportedFeature');
        } catch (UnsupportedFeature $e) {
            $this->assertSame(4258, $e->issue);
            $this->assertStringContainsString('docs/capabilities.md#range', $e->getMessage());
        }

        try {
            UnsupportedFeature::raise('array-walk-recursive-userdata');
            $this->fail('expected UnsupportedFeature');
        } catch (UnsupportedFeature $e) {
            $this->assertSame(4913, $e->issue);
        }
    }

    public function testCallbackAndReflectionSitesUseCatalog(): void
    {
        foreach ([
            'usort-callback-deferred' => \PHPCompiler\JIT\UsortCallbackPolicy::jitRejectionMessage(),
            'array-reduce-callback-deferred' => \PHPCompiler\JIT\ArrayReduceCallbackPolicy::jitRejectionMessage(),
            'spl-autoload-callback-deferred' => \PHPCompiler\JIT\SplAutoloadCallbackPolicy::jitRejectionMessage(),
            'preg-replace-callback-deferred' => \PHPCompiler\JIT\PregReplaceCallbackPolicy::jitRejectionMessage(),
            'array-filter-callback-deferred' => \PHPCompiler\JIT\ArrayFilterCallbackPolicy::jitRejectionMessage(),
        ] as $id => $msg) {
            $this->assertStringStartsWith('phpc: unsupported: ', $msg, $id);
            $row = UnsupportedRegistry::feature($id);
            $this->assertStringContainsString('#'.$row['issue'], $msg, $id);
            $this->assertStringContainsString($row['matrixRow'], $msg, $id);
        }

        try {
            UnsupportedFeature::raise('closure-from-callable-literal');
            $this->fail('expected UnsupportedFeature');
        } catch (UnsupportedFeature $e) {
            $this->assertSame(26788, $e->issue);
        }

        try {
            UnsupportedFeature::raise('attribute-non-constant-arg');
            $this->fail('expected UnsupportedFeature');
        } catch (UnsupportedFeature $e) {
            $this->assertSame(3206, $e->issue);
            $this->assertStringContainsString(
                'Attribute constructor arguments must be compile-time constant expressions',
                $e->getMessage()
            );
        }
    }

    public function testJitHandlerLimitationSitesUseCatalog(): void
    {
        foreach ([
            'backed-enum-from-jit' => 3114,
            'new-first-class-callable-jit' => 9767,
            'isset-static-property-dynamic-name' => 10170,
            'empty-static-property-dynamic-name' => 23983,
            'yield-script-scope-aot' => 3115,
            'reflection-class-name-literal' => 1214,
            'get-class-object-arg' => 1214,
            'get-debug-type-object-arg' => 1214,
            'spl-object-storage-key-type' => 601,
            'foreach-object-container' => 3331,
            'foreach-generator-value' => 167,
        ] as $id => $issue) {
            try {
                UnsupportedFeature::raise($id);
                $this->fail('expected UnsupportedFeature for '.$id);
            } catch (UnsupportedFeature $e) {
                $this->assertSame($issue, $e->issue, $id);
                $this->assertStringStartsWith('phpc: unsupported: ', $e->getMessage(), $id);
                $row = UnsupportedRegistry::feature($id);
                $this->assertStringContainsString($row['matrixRow'], $e->getMessage(), $id);
            }
        }
    }

    public function testKsortWebEnumCastSitesUseCatalog(): void
    {
        foreach ([
            'ksort-flags-numeric-natural' => 4118,
            'arrayobject-offset-key-type' => 26823,
            'web-params-source-array' => 157,
            'web-int-numeric-value' => 157,
            'fiber-suspend-during-clone' => 3130,
            'backed-enum-backing-type-jit' => 4053,
            'object-cast-operand-type' => 10244,
            'array-map-mapped-value-type' => 23974,
            'array-map-null-zip-arity' => 34978,
            'variable-constant-fetch-jit' => 36396,
            'class-const-append-expr' => 3592,
        ] as $id => $issue) {
            try {
                UnsupportedFeature::raise($id);
                $this->fail('expected UnsupportedFeature for '.$id);
            } catch (UnsupportedFeature $e) {
                $this->assertSame($issue, $e->issue, $id);
                $this->assertStringStartsWith('phpc: unsupported: ', $e->getMessage(), $id);
                $row = UnsupportedRegistry::feature($id);
                $this->assertStringContainsString($row['matrixRow'], $e->getMessage(), $id);
            }
        }
    }

    public function testClassFiberReflectionEnumSitesUseCatalog(): void
    {
        foreach ([
            'class-class-literal-jit' => 740,
            'expr-class-operand-jit' => 4179,
            'class-const-dynamic-type-jit' => 740,
            'class-const-type-jit' => 740,
            'generator-yield-value-type-jit' => 3074,
            'fiber-value-type-jit' => 4019,
            'reflection-enum-unknown' => 1214,
            'reflection-enum-unit-case-unknown' => 1214,
        ] as $id => $issue) {
            try {
                UnsupportedFeature::raise($id);
                $this->fail('expected UnsupportedFeature for '.$id);
            } catch (UnsupportedFeature $e) {
                $this->assertSame($issue, $e->issue, $id);
                $this->assertStringStartsWith('phpc: unsupported: ', $e->getMessage(), $id);
                $row = UnsupportedRegistry::feature($id);
                $this->assertStringContainsString($row['matrixRow'], $e->getMessage(), $id);
            }
        }

        $msg = UnsupportedFeature::message('reflection-enum-unknown');
        $this->assertStringStartsWith('phpc: unsupported: ', $msg);
        $this->assertStringContainsString('#1214', $msg);
        $fiberMsg = \PHPCompiler\VM\VmFiberValue::unsupportedMessage();
        $this->assertStringContainsString('#4019', $fiberMsg);
        $this->assertStringStartsWith('phpc: unsupported: ', $fiberMsg);
    }

    public function testReflectionDateUnknownSitesUseCatalog(): void
    {
        foreach ([
            'reflection-enum-backed-case-unknown' => 1214,
            'reflection-class-unknown' => 1214,
            'reflection-method-unknown-class' => 1214,
            'reflection-method-unknown-method' => 1214,
            'reflection-property-unknown-class' => 1214,
            'reflection-constant-unknown-class' => 1214,
            'reflection-class-constant-unknown-class' => 1214,
            'reflection-parameter-unknown-class' => 1214,
            'reflection-parameter-unknown-method' => 1214,
            'dateinterval-not-registered' => 7278,
            'dateperiod-not-registered' => 14144,
            'dateinterval-property-missing' => 7278,
            'dateperiod-property-missing' => 14144,
        ] as $id => $issue) {
            try {
                UnsupportedFeature::raise($id);
                $this->fail('expected UnsupportedFeature for '.$id);
            } catch (UnsupportedFeature $e) {
                $this->assertSame($issue, $e->issue, $id);
                $this->assertStringStartsWith('phpc: unsupported: ', $e->getMessage(), $id);
                $row = UnsupportedRegistry::feature($id);
                $this->assertStringContainsString($row['matrixRow'], $e->getMessage(), $id);
            }
        }

        $override = UnsupportedFeature::message(
            'dateinterval-property-missing',
            'DateInterval property y is missing'
        );
        $this->assertStringContainsString('DateInterval property y is missing', $override);
        $this->assertStringContainsString('#7278', $override);
    }

    /**
     * Sites routed in this slice must not keep the legacy bare LogicException wording.
     */
    public function testRoutedSitesNoLongerUseBareCompilerBuildPhrase(): void
    {
        $roots = [
            dirname(__DIR__, 2).'/ext/standard/range.php',
            dirname(__DIR__, 2).'/ext/standard/substr_replace.php',
            dirname(__DIR__, 2).'/ext/simplexml/JitSimpleXmlLoadString.php',
            dirname(__DIR__, 2).'/lib/JIT/ArrayBuiltinHelper.php',
            dirname(__DIR__, 2).'/lib/JIT/ArrayMapCallbackPolicy.php',
            dirname(__DIR__, 2).'/lib/JIT/Builtin/ArrayWalkRuntime.php',
            dirname(__DIR__, 2).'/lib/JIT/Builtin/MultisortRuntime.php',
            dirname(__DIR__, 2).'/lib/JIT/Builtin/ArrayKeyExistsRuntime.php',
            dirname(__DIR__, 2).'/lib/JIT/HashTableReadLlvm.php',
            dirname(__DIR__, 2).'/lib/JIT/HashTableWriteLlvm.php',
            dirname(__DIR__, 2).'/lib/JIT/IssetHelperLlvm.php',
            dirname(__DIR__, 2).'/lib/JIT/JitStringArg.php',
            dirname(__DIR__, 2).'/lib/JIT/JitLongArg.php',
            dirname(__DIR__, 2).'/lib/JIT/UnsetHelperLlvm.php',
            dirname(__DIR__, 2).'/lib/JIT/Call/ClosureFromCallable.php',
            dirname(__DIR__, 2).'/lib/JIT/Call/ReflectionClassGetStaticPropertyValue.php',
            dirname(__DIR__, 2).'/lib/JIT/Call/ReflectionClassSetStaticPropertyValue.php',
            dirname(__DIR__, 2).'/lib/JIT/Call/ReflectionClassGetConstant.php',
            dirname(__DIR__, 2).'/lib/JIT/Call/ReflectionClassGetConstants.php',
            dirname(__DIR__, 2).'/lib/JIT/Call/DatePeriodConstruct.php',
            dirname(__DIR__, 2).'/lib/JIT/UsortCallbackPolicy.php',
            dirname(__DIR__, 2).'/lib/JIT/ArrayReduceCallbackPolicy.php',
            dirname(__DIR__, 2).'/lib/JIT/SplAutoloadCallbackPolicy.php',
            dirname(__DIR__, 2).'/lib/JIT/PregReplaceCallbackPolicy.php',
            dirname(__DIR__, 2).'/lib/JIT/ArrayFilterCallbackPolicy.php',
            dirname(__DIR__, 2).'/lib/Compiler/AttributeConstantEvaluator.php',
            dirname(__DIR__, 2).'/lib/Func/Internal.php',
            dirname(__DIR__, 2).'/lib/VM/EnumFromHandler.php',
            dirname(__DIR__, 2).'/lib/VM/NewCallableHandler.php',
            dirname(__DIR__, 2).'/lib/JIT/IssetHelper.php',
            dirname(__DIR__, 2).'/lib/JIT/EmptyStaticPropertyHelper.php',
            dirname(__DIR__, 2).'/lib/Runtime.php',
            dirname(__DIR__, 2).'/lib/JIT/ReflectionBuiltinHelper.php',
            dirname(__DIR__, 2).'/lib/VM/VmIteratorForeach.php',
            dirname(__DIR__, 2).'/lib/VM/GeneratorIteratorJitHelper.php',
            dirname(__DIR__, 2).'/lib/VM/ArrayObjectJitHelper.php',
            dirname(__DIR__, 2).'/lib/Web/Params.php',
            dirname(__DIR__, 2).'/lib/VM/Concern/ObjectPropertyMagicAndClone.php',
            dirname(__DIR__, 2).'/lib/JIT/BackedEnumFromJit.php',
            dirname(__DIR__, 2).'/lib/JIT/CastObjectNativeJit.php',
            dirname(__DIR__, 2).'/lib/JIT/ArrayMapLlvm.php',
            dirname(__DIR__, 2).'/lib/JIT/Context.php',
            dirname(__DIR__, 2).'/lib/VM/ClassConstExpr.php',
            dirname(__DIR__, 2).'/lib/JIT/Concern/ClosureThisAndStaticScopeResolve.php',
            dirname(__DIR__, 2).'/lib/JIT/ClassConstFetchHelperTrait.php',
            dirname(__DIR__, 2).'/lib/JIT/Concern/AssignOperandValueMetaAndGeneratorField.php',
            dirname(__DIR__, 2).'/lib/JIT/Builtin/Type/Object_.php',
            dirname(__DIR__, 2).'/lib/JIT/FiberHelperLlvm.php',
            dirname(__DIR__, 2).'/lib/JIT/Builtin/ReflectionEnumJitHelper.php',
            dirname(__DIR__, 2).'/lib/VM/ReflectionSupport.php',
            dirname(__DIR__, 2).'/lib/VM/ReflectionPropertyHookSupport.php',
            dirname(__DIR__, 2).'/lib/VM/DateIntervalSupport.php',
            dirname(__DIR__, 2).'/lib/VM/DatePeriodSupport.php',
            dirname(__DIR__, 2).'/lib/VM/Builtin/ReflectionEnumGetBackingType.php',
            dirname(__DIR__, 2).'/lib/VM/Builtin/ReflectionEnumGetCase.php',
            dirname(__DIR__, 2).'/lib/VM/Builtin/ReflectionEnumGetCases.php',
            dirname(__DIR__, 2).'/lib/VM/Builtin/ReflectionEnumHasCase.php',
            dirname(__DIR__, 2).'/lib/VM/Builtin/ReflectionEnumIsBacked.php',
            dirname(__DIR__, 2).'/lib/VM/Builtin/ReflectionEnumUnitCaseIsDeprecated.php',
            dirname(__DIR__, 2).'/lib/VM/Builtin/ReflectionEnumBackedCaseGetBackingValue.php',
            dirname(__DIR__, 2).'/lib/VM/Builtin/ReflectionClassIsInternal.php',
            dirname(__DIR__, 2).'/lib/VM/Builtin/ReflectionPropertyIsStatic.php',
            dirname(__DIR__, 2).'/lib/VM/Builtin/ReflectionMethodGetParameters.php',
            dirname(__DIR__, 2).'/lib/VM/Builtin/ReflectionConstantGetValue.php',
            dirname(__DIR__, 2).'/lib/VM/Builtin/ReflectionClassConstantIsFinal.php',
        ];
        $legacy = [
            'range() step must be an integer in this compiler build',
            'array_map() with multiple arrays requires a null, closure, or compile-time string builtin callback for JIT/AOT in this compiler build',
            'array_walk_recursive() userdata is not supported for JIT/AOT in this compiler build',
            'substr_replace() array $replace with array $string is not supported in this compiler build',
            'simplexml_load_string() is not JIT-lowered in this compiler build',
            'array_multisort() requires at least two array arguments in this compiler build',
            'isset() on HashTable arrays only supports integer or string indices in this compiler build',
            'Array fetch only supports integer or string indices in this compiler build',
            'unset() array offset requires int or string index in this compiler build',
            'isset() with array offset on object containers only supports SplObjectStorage or typed object properties in this compiler build',
            'must be a string in this compiler build',
            'must be an integer in this compiler build',
            'unset() offset only supports arrays and objects in this compiler build',
            'Closure::fromCallable() requires a compile-time string callable in this compiler build',
            'ReflectionClass::getStaticPropertyValue() name must be a string literal in this compiler build',
            'ReflectionClass::setStaticPropertyValue() name must be a string literal in this compiler build',
            'ReflectionClass::getConstant() name must be a string literal in this compiler build',
            'ReflectionClass::getConstants() filter must be a compile-time int in this compiler build',
            'or VM path in this compiler build (#26772)',
            'for JIT/AOT in this compiler build; array callables and invokable objects are deferred',
            'Attribute constructor arguments must be compile-time constant expressions in this compiler build',
            'is not supported in JIT in this compiler build',
            'new(...) first-class callable is not supported in JIT in this compiler build',
            'isset() on static property with dynamic name is not supported in JIT',
            'empty() on static property with dynamic name is not supported in JIT',
            'yield in the main script is not supported in AOT yet (issue #3115).',
            'must be a string literal in this compiler build',
            'get_class() argument must be an object in this compiler build',
            'get_debug_type() argument must be an object in this compiler build',
            'SplObjectStorage keys must be objects in this compiler build',
            'foreach over objects is only supported for SplObjectStorage in this compiler build',
            'foreach requires a Generator value in this compiler build',
            'ksort() flags are not supported in JIT/AOT in this compiler build',
            'web_*() first argument must be an array in this compiler build',
            'web_int() value must be numeric in this compiler build',
            'Fiber suspend during __clone() is not supported in this compiler build',
            'array_map(null) multi-zip requires ≥2 source hashtables (#34978)',
            'Variable constant fetch not supported yet',
            '[] append is not supported in class constant expressions',
            'Class::class requires a literal class name for JIT/AOT',
            'Unsupported operand for expression ::class in JIT',
            'Unsupported class constant type for dynamic JIT fetch',
            'Unsupported class constant type for JIT',
            'Unsupported generator yield value type in JIT (issue #3074)',
            'Unsupported fiber value type in JIT (issue #4019)',
            'ReflectionEnum refers to unknown enum in this compiler build',
            'ReflectionEnumUnitCase refers to unknown enum in this compiler build',
            'ReflectionEnumBackedCase refers to unknown backed enum in this compiler build',
            'ReflectionClass refers to unknown class in this compiler build',
            'ReflectionMethod refers to unknown class in this compiler build',
            'ReflectionMethod refers to unknown method in this compiler build',
            'ReflectionProperty refers to unknown class in this compiler build',
            'ReflectionConstant refers to unknown class in this compiler build',
            'ReflectionClassConstant refers to unknown class in this compiler build',
            'ReflectionParameter refers to unknown class in this compiler build',
            'ReflectionParameter refers to unknown method in this compiler build',
            'DateInterval is not registered in this compiler build',
            'DatePeriod is not registered in this compiler build',
            'DateInterval days property is missing in this compiler build',
            'DateInterval property {$name} is missing in this compiler build',
            'DatePeriod property {$name} is missing in this compiler build',
            'DatePeriod start property is missing in this compiler build',
            'DatePeriod interval property is missing in this compiler build',
        ];
        foreach ($roots as $path) {
            $this->assertFileExists($path);
            $src = file_get_contents($path);
            $this->assertNotFalse($src);
            foreach ($legacy as $needle) {
                $this->assertStringNotContainsString($needle, $src, basename($path).' still has legacy: '.$needle);
            }
        }

        // Sites that keep a dynamic featureOverride must still raise via UnsupportedFeature.
        foreach ([
            dirname(__DIR__, 2).'/lib/VM/ArrayObjectJitHelper.php' => 'arrayobject-offset-key-type',
            dirname(__DIR__, 2).'/lib/JIT/BackedEnumFromJit.php' => 'backed-enum-backing-type-jit',
            dirname(__DIR__, 2).'/lib/JIT/CastObjectNativeJit.php' => 'object-cast-operand-type',
            dirname(__DIR__, 2).'/lib/JIT/ArrayMapLlvm.php' => 'array-map-mapped-value-type',
            dirname(__DIR__, 2).'/lib/JIT/Concern/ClosureThisAndStaticScopeResolve.php' => 'class-class-literal-jit',
            dirname(__DIR__, 2).'/lib/JIT/ClassConstFetchHelperTrait.php' => 'expr-class-operand-jit',
            dirname(__DIR__, 2).'/lib/JIT/Concern/AssignOperandValueMetaAndGeneratorField.php' => 'generator-yield-value-type-jit',
            dirname(__DIR__, 2).'/lib/JIT/Builtin/Type/Object_.php' => 'class-const-type-jit',
            dirname(__DIR__, 2).'/lib/JIT/FiberHelperLlvm.php' => 'fiber-value-type-jit',
            dirname(__DIR__, 2).'/lib/JIT/Builtin/ReflectionEnumJitHelper.php' => 'reflection-enum-unknown',
            dirname(__DIR__, 2).'/lib/VM/Builtin/ReflectionEnumGetBackingType.php' => 'reflection-enum-unknown',
            dirname(__DIR__, 2).'/lib/VM/Builtin/ReflectionClassIsInternal.php' => 'reflection-class-unknown',
            dirname(__DIR__, 2).'/lib/VM/Builtin/ReflectionPropertyIsStatic.php' => 'reflection-property-unknown-class',
            dirname(__DIR__, 2).'/lib/VM/DateIntervalSupport.php' => 'dateinterval-not-registered',
            dirname(__DIR__, 2).'/lib/VM/DatePeriodSupport.php' => 'dateperiod-not-registered',
            dirname(__DIR__, 2).'/lib/VM/ReflectionSupport.php' => 'reflection-class-unknown',
        ] as $path => $featureId) {
            $src = file_get_contents($path);
            $this->assertNotFalse($src);
            $this->assertStringContainsString("'".$featureId."'", $src, basename($path));
            $this->assertTrue(
                str_contains($src, 'UnsupportedFeature::raise')
                    || str_contains($src, 'UnsupportedFeature::message'),
                basename($path).' must call UnsupportedFeature::raise/message'
            );
        }
    }
}
