<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__).'/aot/AotTest.php';

/** @group aot-lint */
final class MiniWebAppAotBisectGroupTest extends TestCase
{
    public function testBisectMethodIsTaggedOnAotTest(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__).'/aot/AotTest.php');
        $this->assertStringContainsString('@group miniwebapp-bisect', $source);
        $this->assertStringContainsString('testMiniWebAppBisectCases', $source);
        $this->assertStringContainsString('provideMiniWebAppBisectPHPTests', $source);
        $this->assertStringContainsString('@group aot-link', $source);
        $this->assertStringContainsString('testCases', $source);
    }

    public function testManifestMatchesOrderedLadder(): void
    {
        $expected = [
            'isset_object_property_array',
            'require_return_config',
            'nested_include_two_tier',
            'miniwebapp_render_home',
            'layout_script_base',
            'layout_title_branch',
            'method_include_void_array_property',
        ];
        $this->assertSame($expected, AotTest::MINIWEBAPP_BISECT_CASES);
        $casesDir = dirname(__DIR__, 2).'/test/fixtures/aot/cases';
        foreach ($expected as $basename) {
            $this->assertFileExists($casesDir.'/'.$basename.'.phpt', $basename);
        }
    }

    public function testFixturesReadmeDocumentsLadder(): void
    {
        $readme = (string) file_get_contents(dirname(__DIR__, 2).'/test/fixtures/aot/cases/README.md');
        $this->assertStringContainsString('miniwebapp-bisect', $readme);
        $this->assertStringContainsString('layout_title_branch', $readme);
    }
}
