<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * SoapClient::__getFunctions Zend function_to_string signatures (#31473).
 *
 * Requires host php-soap so SoapExtensionPolicy advertises the extension.
 */
final class Issue31473SoapGetFunctionsSignaturesTest extends TestCase
{
    public function testGetFunctionsMatchesZendFunctionToString(): void
    {
        if (!\extension_loaded('soap')) {
            $this->markTestSkipped('host php-soap required for soap advertisement');
        }

        $root = \dirname(__DIR__, 2);
        $script = $root.'/test/repro/issue_31473_soap_getfunctions_signatures.php';
        $this->assertFileExists($script);

        $php = \PHP_BINARY;
        $vm = $root.'/bin/vm.php';
        $cmd = \escapeshellarg($php).' '.\escapeshellarg($vm).' '.\escapeshellarg($script).' 2>&1';
        $out = \shell_exec($cmd);
        $this->assertIsString($out);
        $this->assertSame(
            "echo=echoResponse echo(echo \$parameters)\nbook=getBookResponse getBook(getBook \$parameters)\n",
            $out
        );
    }
}
