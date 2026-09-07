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
        ];
        foreach ($roots as $path) {
            $this->assertFileExists($path);
            $src = file_get_contents($path);
            $this->assertNotFalse($src);
            foreach ($legacy as $needle) {
                $this->assertStringNotContainsString($needle, $src, basename($path).' still has legacy: '.$needle);
            }
        }
    }
}
