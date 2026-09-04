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
}
