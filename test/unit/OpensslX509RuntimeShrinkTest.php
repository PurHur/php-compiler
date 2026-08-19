<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * openssl_x509_parse / fingerprint / verify / export JIT/AOT bake libcrypto FFI in the compiler process
 * (#32496 leftover of #6274; #32512 leftover of #6524; #32535 leftover of #6595; #32557 leftover of #20273).
 */
final class OpensslX509RuntimeShrinkTest extends TestCase
{
    public function testJitOpensslX509BakesParseResult(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/openssl/JitOpensslX509.php');
        $this->assertStringContainsString('VmOpensslX509Native::parseCertificatePem', $source);
        $this->assertStringContainsString('VmOpensslX509Native::fingerprintCertificatePem', $source);
        $this->assertStringContainsString('VmOpensslX509Native::verifyCertificatePem', $source);
        $this->assertStringContainsString('VmOpensslX509Native::exportCertificatePem', $source);
        $this->assertStringContainsString('__compiler_file_put_contents', $source);
        $this->assertStringContainsString('HashTableHelper::variableFromVmHashTable', $source);
        $this->assertStringContainsString('compile-time string literal', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/AOT/runtime/openssl_x509_parse.c');
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/AOT/runtime/openssl_x509_fingerprint.c');
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/AOT/runtime/openssl_x509_verify.c');
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/AOT/runtime/openssl_x509_export.c');
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/AOT/runtime/openssl_x509_export_to_file.c');
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/OpensslX509Runtime.php');
    }

    public function testSpineBundleIncludesJitOpensslX509(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('JitOpensslX509.php', $spine);
        $this->assertStringNotContainsString('OpensslX509Runtime.php', $spine);
        $this->assertStringNotContainsString('OpensslX509JitHelper.php', $spine);
    }
}
