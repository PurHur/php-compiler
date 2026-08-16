<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * SoapClient::__getTypes element-inline anonymous complexTypes (#31474).
 *
 * Requires host php-soap so SoapExtensionPolicy advertises the extension.
 */
final class Issue31474SoapGetTypesElementInlineTest extends TestCase
{
    public function testGetTypesIncludesElementInlineAnonymousComplexTypes(): void
    {
        if (!\extension_loaded('soap')) {
            $this->markTestSkipped('host php-soap required for soap advertisement');
        }

        $root = \dirname(__DIR__, 2);
        $script = $root.'/test/repro/issue_31474_soap_gettypes_element_inline.php';
        $this->assertFileExists($script);

        $php = \PHP_BINARY;
        $vm = $root.'/bin/vm.php';
        $cmd = \escapeshellarg($php).' '.\escapeshellarg($vm).' '.\escapeshellarg($script).' 2>&1';
        $out = \shell_exec($cmd);
        $this->assertIsString($out);
        $this->assertSame(
            "echo_count=2\necho_has=1\nbook_count=3\nbook_has=1\n",
            $out
        );
    }
}
