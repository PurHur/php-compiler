<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * openssl_x509_parse / fingerprint / verify / export JIT/AOT bake libcrypto FFI in the compiler process
 * (#32496 leftover of #6274; #32512 leftover of #6524; #32535 leftover of #6595; #32557 leftover of #20273;
 * #32692 leftover of #6421 openssl_csr_get_subject;
 * #32697 leftover of #6421 openssl_csr_export / openssl_csr_export_to_file;
 * #32705 leftover of #6295/#20287 openssl_pkey_export / openssl_pkey_export_to_file;
 * #32713 leftover of #6666 openssl_public_encrypt;
 * #32757 leftover of #6666 openssl_private_encrypt;
 * #32759 leftover of #6666 openssl_private_decrypt;
 * #32761 leftover of #6666 openssl_public_decrypt;
 * #32771 leftover of #6596 openssl_dh_compute_key;
 * #32776 leftover of #8690 openssl_spki_verify;
 * #32787 leftover of #6423 openssl_spki_export;
 * #32792 leftover of #6423 openssl_spki_export_challenge;
 * #32892 leftover of #8690 openssl_spki_new).
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
        $this->assertStringContainsString('VmOpensslCsrNative::getSubject', $source);
        $this->assertStringContainsString('VmOpensslCsrNative::normalizeCsrPem', $source);
        $this->assertStringContainsString('VmOpensslPkeyNative::exportPrivateKeyPem', $source);
        $this->assertStringContainsString('VmOpensslPkeyNative::encrypt', $source);
        $this->assertStringContainsString('VmOpensslPkeyNative::privateEncrypt', $source);
        $this->assertStringContainsString('VmOpensslPkeyNative::decrypt', $source);
        $this->assertStringContainsString('VmOpensslPkeyNative::publicDecrypt', $source);
        $this->assertStringContainsString('VmOpensslPkeyDeriveNative::dhComputeKey', $source);
        $this->assertStringContainsString('VmOpensslSpkiNative::spkiVerify', $source);
        $this->assertStringContainsString('VmOpensslSpkiNative::spkiExport', $source);
        $this->assertStringContainsString('VmOpensslSpkiNative::spkiExportChallenge', $source);
        $this->assertStringContainsString('VmOpensslSpkiNative::spkiNew', $source);
        $this->assertStringContainsString('__compiler_file_put_contents', $source);
        $this->assertStringContainsString('HashTableHelper::variableFromVmHashTable', $source);
        $this->assertStringContainsString('compile-time string literal', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/AOT/runtime/openssl_x509_parse.c');
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/AOT/runtime/openssl_x509_fingerprint.c');
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/AOT/runtime/openssl_x509_verify.c');
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/AOT/runtime/openssl_x509_export.c');
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/AOT/runtime/openssl_x509_export_to_file.c');
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/AOT/runtime/openssl_csr_get_subject.c');
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/AOT/runtime/openssl_csr_export.c');
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/AOT/runtime/openssl_csr_export_to_file.c');
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/AOT/runtime/openssl_pkey_export.c');
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/AOT/runtime/openssl_pkey_export_to_file.c');
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/AOT/runtime/openssl_public_encrypt.c');
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/AOT/runtime/openssl_private_encrypt.c');
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/AOT/runtime/openssl_private_decrypt.c');
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/AOT/runtime/openssl_public_decrypt.c');
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/AOT/runtime/openssl_dh_compute_key.c');
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/AOT/runtime/openssl_spki_verify.c');
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/AOT/runtime/openssl_spki_export.c');
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/AOT/runtime/openssl_spki_export_challenge.c');
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/AOT/runtime/openssl_spki_new.c');
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
