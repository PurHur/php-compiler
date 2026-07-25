<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Gen-0 provenance may only be stamped by the link that produced the committed blobs (#22642).
 *
 * Before build receipts, `lowering_source_fingerprint` could be restamped onto blobs that were
 * never rebuilt: the committed driver bytes last moved 2026-06-15 while the manifest was
 * restamped 225 times, and release-readiness went green on every one of them.
 */
final class BootstrapGen0BuildReceiptTest extends TestCase
{
    private const FP_A = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const FP_B = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private string $root;

    public static function setUpBeforeClass(): void
    {
        require_once \dirname(__DIR__, 2).'/script/bootstrap-gen0-manifest-lib.php';
    }

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/phpc-gen0-receipt-'.bin2hex(random_bytes(6));
        mkdir($this->root.'/build', 0775, true);
        mkdir($this->root.'/prelinked/bootstrap-gen0', 0775, true);
        putenv('BOOTSTRAP_GEN0_ALLOW_UNVERIFIED_STAMP');
    }

    protected function tearDown(): void
    {
        putenv('BOOTSTRAP_GEN0_ALLOW_UNVERIFIED_STAMP');
        $this->removeTree($this->root);
    }

    public function testStampRefusesWithoutAReceipt(): void
    {
        $this->linkProducing('gen-0 bytes v1');
        $this->publish();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/without a matching build receipt/');
        bootstrap_gen0_manifest_stamp_lowering_fingerprint($this->root, self::FP_A);
    }

    public function testStampSucceedsForBlobsTheReceiptCovers(): void
    {
        $this->linkProducing('gen-0 bytes v1');
        bootstrap_gen0_write_build_receipt($this->root, self::FP_A);
        $this->publish();

        $manifest = bootstrap_gen0_manifest_stamp_lowering_fingerprint($this->root, self::FP_A);

        $this->assertSame(self::FP_A, $manifest['lowering_source_fingerprint']);
        $this->assertSame('verified-fresh', $manifest['provenance']);
        $this->assertSame([], bootstrap_gen0_build_receipt_errors($this->root, self::FP_A));
    }

    /** The 225-restamp bug: sources moved on, blobs did not. */
    public function testStampRefusesNewFingerprintOverUnrebuiltBlobs(): void
    {
        $this->linkProducing('gen-0 bytes v1');
        bootstrap_gen0_write_build_receipt($this->root, self::FP_A);
        $this->publish();
        bootstrap_gen0_manifest_stamp_lowering_fingerprint($this->root, self::FP_A);

        // Lowering sources drift to FP_B; nobody relinks. The blobs are still v1.
        $errors = bootstrap_gen0_build_receipt_errors($this->root, self::FP_B);
        $this->assertNotSame([], $errors);
        $this->assertStringContainsString('build receipt records fingerprint', $errors[0]);

        $this->expectException(\RuntimeException::class);
        bootstrap_gen0_manifest_stamp_lowering_fingerprint($this->root, self::FP_B);
    }

    /** A receipt at the right fingerprint still fails if the committed bytes are not its output. */
    public function testStampRefusesWhenCommittedBytesAreNotTheLinkedArtifact(): void
    {
        $this->linkProducing('gen-0 bytes v2');
        bootstrap_gen0_write_build_receipt($this->root, self::FP_A);
        $this->publish();

        // Someone drops an older driver back in beside a fresh receipt.
        file_put_contents($this->root.'/prelinked/bootstrap-gen0/bin-compile-aot', 'gen-0 bytes v1 driver');
        bootstrap_gen0_manifest_refresh_from_disk($this->root);

        $errors = bootstrap_gen0_build_receipt_errors($this->root, self::FP_A);
        $this->assertCount(1, $errors);
        $this->assertStringContainsString('were not rebuilt', $errors[0]);

        $this->expectException(\RuntimeException::class);
        bootstrap_gen0_manifest_stamp_lowering_fingerprint($this->root, self::FP_A);
    }

    public function testOverrideRecordsTheClaimAsUnverifiedAndWarns(): void
    {
        $this->linkProducing('gen-0 bytes v1');
        $this->publish();

        putenv('BOOTSTRAP_GEN0_ALLOW_UNVERIFIED_STAMP=1');
        $manifest = bootstrap_gen0_manifest_stamp_lowering_fingerprint($this->root, self::FP_B);

        $this->assertSame(self::FP_B, $manifest['lowering_source_fingerprint']);
        $this->assertSame('unverified-restamp', $manifest['provenance']);

        $warnings = bootstrap_gen0_manifest_sync_warnings($this->root);
        $this->assertNotSame([], $warnings);
        $this->assertStringContainsString('unverified-restamp', implode("\n", $warnings));
    }

    /** Write the build/ artifacts a spine link would leave behind, including its linked binary. */
    private function linkProducing(string $tag): void
    {
        file_put_contents($this->root.'/build/selfhost-lib-spine-smoke', $tag.' spine binary');
        file_put_contents($this->root.'/build/bin-compile-aot', $tag.' driver');
        file_put_contents($this->root.'/build/.m3_compiler_minimal_aot_blob', $tag.' minimal');
        file_put_contents($this->root.'/build/.m3_compiler_lib_aot_blob', $tag.' lib');
    }

    /** Copy build/ artifacts into prelinked/ and refresh manifest size/sha, as the refresh script does. */
    private function publish(): void
    {
        $gen0 = $this->root.'/prelinked/bootstrap-gen0';
        copy($this->root.'/build/bin-compile-aot', $gen0.'/bin-compile-aot');
        copy($this->root.'/build/bin-compile-aot', $gen0.'/.m3_bin_compile_aot_blob');
        copy($this->root.'/build/.m3_compiler_minimal_aot_blob', $gen0.'/compiler_minimal_aot_blob');
        copy($this->root.'/build/.m3_compiler_lib_aot_blob', $gen0.'/compiler_lib_aot_blob');

        file_put_contents($gen0.'/manifest.json', json_encode([
            'version' => 1,
            'driver' => 'prelinked/bootstrap-gen0/bin-compile-aot',
            'compiler_minimal_sidecar' => 'prelinked/bootstrap-gen0/compiler_minimal_aot_blob',
            'compiler_lib_sidecar' => 'prelinked/bootstrap-gen0/compiler_lib_aot_blob',
        ], JSON_PRETTY_PRINT)."\n");

        bootstrap_gen0_manifest_refresh_from_disk($this->root);
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($dir);
    }
}
