<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** Issue #32908 — ternary property fetch in CONCAT must use stack-phi like echo merge. */
final class DomItemTernaryConcat32908AotTest extends TestCase
{
    public function testReproMatchesZendOnVm(): void
    {
        $code = file_get_contents(__DIR__ . '/../repro/issue_dom_item_ternary_concat.php');
        self::assertIsString($code);
        $runtime = new Runtime();
        $script = $runtime->parse($code, 'issue_32908.php');
        $compiled = $runtime->compile($script);
        ob_start();
        $runtime->run($compiled);
        $out = ob_get_clean();
        self::assertSame("b|c\nb|c\n", $out);
    }

    public function testFixtureCatalogListsCase(): void
    {
        $catalog = dirname(__DIR__) . '/fixtures/aot/cases/dom_item_ternary_concat_32908.phpt';
        self::assertFileExists($catalog);
        $body = file_get_contents($catalog);
        self::assertIsString($body);
        self::assertStringContainsString('b|c', $body);
        self::assertStringContainsString('#32908', $body);
    }
}
