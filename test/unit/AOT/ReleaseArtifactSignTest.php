<?php

declare(strict_types=1);

namespace PHPCompiler\AOT;

use PHPUnit\Framework\TestCase;

/**
 * #36399: SHA256SUMS + openssl detached signature for release artifacts.
 */
final class ReleaseArtifactSignTest extends TestCase
{
    public function testSignAndVerifyRoundTrip(): void
    {
        $root = dirname(__DIR__, 3);
        $tmp = sys_get_temp_dir().'/phpc-release-sign-'.bin2hex(random_bytes(4));
        mkdir($tmp, 0775, true);
        $artifact = $tmp.'/phpc-test.tar.gz';
        file_put_contents($artifact, "php-compiler-release-fixture\n");

        try {
            $write = escapeshellarg($root.'/script/write-release-checksums.sh').' '
                .escapeshellarg($tmp).' '.escapeshellarg($artifact);
            exec($write.' 2>&1', $outWrite, $codeWrite);
            $this->assertSame(0, $codeWrite, implode("\n", $outWrite));
            $this->assertFileExists($tmp.'/SHA256SUMS');

            $sign = escapeshellarg($root.'/script/sign-release-artifacts.sh').' '
                .escapeshellarg($tmp);
            exec($sign.' 2>&1', $outSign, $codeSign);
            $this->assertSame(0, $codeSign, implode("\n", $outSign));
            $this->assertFileExists($tmp.'/SHA256SUMS.sig');
            $this->assertFileExists($tmp.'/SHA256SUMS.sig.pub.pem');
            $this->assertFileDoesNotExist($tmp.'/.phpc-release-signing-key.pem');

            $verify = 'PHPC_RELEASE_REQUIRE_SIGNATURE=1 '
                .escapeshellarg($root.'/script/verify-release-artifacts.sh').' '
                .escapeshellarg($tmp);
            exec($verify.' 2>&1', $outVerify, $codeVerify);
            $this->assertSame(0, $codeVerify, implode("\n", $outVerify));
            $joined = implode("\n", $outVerify);
            $this->assertStringContainsString('signature OK', $joined);

            // Tamper: checksums must fail.
            file_put_contents($artifact, "tampered\n");
            exec($verify.' 2>&1', $outBad, $codeBad);
            $this->assertNotSame(0, $codeBad, implode("\n", $outBad));
        } finally {
            foreach (glob($tmp.'/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($tmp);
        }
    }

    public function testPackSdkWiresSignAndVerify(): void
    {
        $root = dirname(__DIR__, 3);
        $body = (string) file_get_contents($root.'/script/pack-phpc-sdk.sh');
        $this->assertStringContainsString('sign-release-artifacts.sh', $body);
        $this->assertStringContainsString('verify-release-artifacts.sh', $body);
        $this->assertStringContainsString('PHPC_RELEASE_REQUIRE_SIGNATURE=1', $body);
    }

    public function testCommittedCiTestKeyIsDurableAndPreferred(): void
    {
        $root = dirname(__DIR__, 3);
        $key = $root.'/keys/ci-release-test/rsa.pem';
        $pub = $root.'/keys/ci-release-test/rsa.pub.pem';
        $this->assertFileExists($key);
        $this->assertFileExists($pub);
        $this->assertFileExists($root.'/keys/ci-release-test/README.md');

        $signBody = (string) file_get_contents($root.'/script/sign-release-artifacts.sh');
        $this->assertStringContainsString('keys/ci-release-test/rsa.pem', $signBody);
        $this->assertStringContainsString('committed CI test key', $signBody);

        // Same SHA256SUMS signed twice with the durable key must yield identical .sig bytes.
        $tmp = sys_get_temp_dir().'/phpc-release-durable-'.bin2hex(random_bytes(4));
        mkdir($tmp, 0775, true);
        $artifact = $tmp.'/phpc-test.tar.gz';
        file_put_contents($artifact, "php-compiler-durable-key-fixture\n");
        try {
            $write = escapeshellarg($root.'/script/write-release-checksums.sh').' '
                .escapeshellarg($tmp).' '.escapeshellarg($artifact);
            exec($write.' 2>&1', $outWrite, $codeWrite);
            $this->assertSame(0, $codeWrite, implode("\n", $outWrite));

            $sign = escapeshellarg($root.'/script/sign-release-artifacts.sh').' '
                .escapeshellarg($tmp);
            exec($sign.' 2>&1', $outSign1, $codeSign1);
            $this->assertSame(0, $codeSign1, implode("\n", $outSign1));
            $joined1 = implode("\n", $outSign1);
            $this->assertStringContainsString('committed CI test key', $joined1);
            $sig1 = (string) file_get_contents($tmp.'/SHA256SUMS.sig');
            $pubCopied = (string) file_get_contents($tmp.'/SHA256SUMS.sig.pub.pem');
            $this->assertSame(file_get_contents($pub), $pubCopied);

            unlink($tmp.'/SHA256SUMS.sig');
            unlink($tmp.'/SHA256SUMS.sig.pub.pem');
            exec($sign.' 2>&1', $outSign2, $codeSign2);
            $this->assertSame(0, $codeSign2, implode("\n", $outSign2));
            $sig2 = (string) file_get_contents($tmp.'/SHA256SUMS.sig');
            $this->assertSame($sig1, $sig2, 'durable CI test key must produce identical signatures');
        } finally {
            foreach (glob($tmp.'/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($tmp);
        }
    }
}
