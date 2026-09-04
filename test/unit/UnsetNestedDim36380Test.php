<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Nested unset($a[0]['k']) must mutate the live element (Part of #36380).
 *
 * php-src: Zend/zend_compile.c ZEND_FETCH_DIM_W + ZEND_UNSET_DIM.
 */
final class UnsetNestedDim36380Test extends TestCase
{
    public function testNestedUnsetRemovesStringKey(): void
    {
        $code = file_get_contents(__DIR__ . '/../repro/unset_nested_string_key_36380.php');
        self::assertIsString($code);
        $runtime = new Runtime(Runtime::MODE_NORMAL);
        $block = $runtime->parseAndCompile($code, 'unset_nested_string_key_36380.php');
        self::assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $out = (string) ob_get_clean();
        self::assertStringContainsString("OK\n", $out);
        self::assertStringContainsString("OK3\n", $out);
        self::assertStringNotContainsString('BAD', $out);
    }

    public function testParsedownTightListStripsParagraph(): void
    {
        $code = file_get_contents(__DIR__ . '/../repro/parsedown_tight_list_36380.php');
        self::assertIsString($code);
        $runtime = new Runtime(Runtime::MODE_NORMAL);
        $block = $runtime->parseAndCompile($code, 'parsedown_tight_list_36380.php');
        self::assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $out = (string) ob_get_clean();
        self::assertSame("OK\n", $out);
    }
}
