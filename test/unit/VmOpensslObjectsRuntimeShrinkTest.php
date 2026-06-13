<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** VmOpensslObjects x509 lifecycle without host ext/openssl delegation (#8306, #7268). */
final class VmOpensslObjectsRuntimeShrinkTest extends TestCase
{
    public function testVmOpensslObjectsDoesNotDelegateToHostOpenssl(): void
    {
        $objects = (string) file_get_contents(dirname(__DIR__, 2).'/ext/openssl/VmOpensslObjects.php');
        $native = (string) file_get_contents(dirname(__DIR__, 2).'/ext/openssl/VmOpensslX509Native.php');

        $this->assertStringContainsString('VmOpensslX509Native::normalizeCertificatePem', $objects);
        $this->assertStringNotContainsString("function_exists('openssl_x509_parse')", $objects);
        $this->assertStringNotContainsString("function_exists('openssl_x509_export')", $objects);
        $this->assertStringNotContainsString('\\openssl_x509_parse(', $objects);
        $this->assertStringNotContainsString('\\openssl_x509_read(', $objects);
        $this->assertStringNotContainsString('\\openssl_x509_export(', $objects);
        $this->assertStringContainsString('PEM_read_bio_X509', $native);
        $this->assertStringNotContainsString('\\openssl_x509_', $native);
    }
}
