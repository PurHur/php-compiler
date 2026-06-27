<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\PropertyHookProfileSkipTrait;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #7097 */
final class PropertyHookStaticTest extends TestCase
{
    use PropertyHookProfileSkipTrait;

    protected function setUp(): void
    {
        $this->skipUnlessPropertyHooks();
    }

    public function testStaticInPropertyHookGetBodyPersistsAcrossReads(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(__DIR__.'/../repro-maintainer/property_hook_static.php');
        $block = $runtime->parseAndCompile($code, 'property_hook_static.php');
        self::assertNotNull($block);
        ob_start();
        $runtime->run($block);
        self::assertSame("1\n2\n", ob_get_clean());
    }
}
