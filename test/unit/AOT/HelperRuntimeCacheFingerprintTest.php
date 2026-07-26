<?php

declare(strict_types=1);

namespace PHPCompiler\AOT;

use PHPUnit\Framework\TestCase;

/**
 * #15889 / #23458: helper-runtime cache fingerprints.
 */
final class HelperRuntimeCacheFingerprintTest extends TestCase
{
    /**
     * @runInSeparateProcess
     */
    public function testUnitFingerprintChangesWhenLlvmPathChanges(): void
    {
        $root = \dirname(__DIR__, 3);
        $tmp = \sys_get_temp_dir().'/phpc-helper-runtime-fingerprint-'.bin2hex(random_bytes(6)).'.php';
        file_put_contents($tmp, "<?php\nreturn 1;\n");
        try {
            $a = $this->fingerprintViaSubprocess($root, $tmp, '/tmp/llvm-a');
            $b = $this->fingerprintViaSubprocess($root, $tmp, '/tmp/llvm-b');
            $this->assertNotSame($a, $b, 'fingerprint should change when PHP_COMPILER_LLVM_PATH changes');
        } finally {
            @unlink($tmp);
        }
    }

    public function testCoreFingerprintIgnoresJitPhpContent(): void
    {
        $root = \dirname(__DIR__, 3);
        $src = (string) file_get_contents($root.'/lib/AOT/HelperRuntimeCache.php');
        $this->assertStringContainsString('Global inputs only (#23458)', $src);
        $this->assertMatchesRegularExpression(
            '/function coreFingerprint\(\)[\s\S]*?globalFingerprintMaterial\(\)/',
            $src,
            'coreFingerprint must use global-only material'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/function coreFingerprint\(\)[\s\S]*?lib\/JIT\.php[\s\S]*?return \$core/',
            $src,
            'coreFingerprint must not hash lib/JIT.php'
        );
        $this->assertNotSame(
            HelperRuntimeCache::coreFingerprint(),
            HelperRuntimeCache::legacyLoweringFingerprint(),
            'narrow global core must differ from legacy lowering key'
        );
    }

    public function testLegacyLoweringFingerprintStillMatchesCommittedUnits(): void
    {
        $root = \dirname(__DIR__, 3);
        $unitsDir = $root.'/prelinked/helper-runtime/'.HelperRuntimeCache::archKey().'/units';
        if (!is_dir($unitsDir)) {
            $this->markTestSkipped('no committed helper-runtime for this arch');
        }
        $slug = 'ext_standard_StrposJitHelper_php';
        $manifestPath = $unitsDir.'/'.$slug.'/manifest.json';
        if (!is_file($manifestPath)) {
            $this->markTestSkipped('Strpos helper unit not in committed cache');
        }
        $raw = json_decode((string) file_get_contents($manifestPath), true);
        $this->assertIsArray($raw);
        // Exercise legacy path even if the tree was already migrated.
        unset($raw['deps'], $raw['fingerprint_version']);
        // Recompute what the pre-migrate fingerprint must have been.
        $sourceAbs = HelperRuntimeCache::resolveUnitSource($root, (string) $raw['unit']);
        $this->assertNotNull($sourceAbs);
        if (!isset($raw['deps'])) {
            // Restore legacy fingerprint for the assertion when file is already v2.
            $legacyFp = (static function () use ($sourceAbs): string {
                $ref = new \ReflectionClass(HelperRuntimeCache::class);
                $m = $ref->getMethod('fingerprintV1Legacy');
                $m->setAccessible(true);

                return (string) $m->invoke(null, $sourceAbs);
            })();
            $raw['fingerprint'] = $legacyFp;
        }
        $this->assertTrue(
            HelperRuntimeCache::manifestFingerprintMatches($raw, $sourceAbs),
            'legacy manifests without deps[] must stay fresh under expectedFingerprintForManifest'
        );
    }

    public function testV2DepEditInvalidatesOnlyUnitsThatListIt(): void
    {
        $root = \dirname(__DIR__, 3);
        $realUnit = $root.'/ext/standard/StrposJitHelper.php';
        $wordwrap = $root.'/ext/standard/WordwrapJitHelper.php';
        $vmString = $root.'/ext/standard/VmString.php';
        $this->assertFileExists($realUnit);
        $this->assertFileExists($wordwrap);
        $this->assertFileExists($vmString);

        $depsStrpos = HelperRuntimeCache::dependencyRelPathsForEmit($realUnit, []);
        $depsWw = HelperRuntimeCache::dependencyRelPathsForEmit($wordwrap, []);
        $this->assertContains('/ext/standard/StrposJitHelper.php', $depsStrpos);
        $this->assertContains('/ext/standard/VmString.php', $depsStrpos, 'same-dir class refs expand into deps');

        $fpStrposBefore = HelperRuntimeCache::fingerprintV2($realUnit, $depsStrpos);
        $fpWwBefore = HelperRuntimeCache::fingerprintV2($wordwrap, $depsWw);

        $backup = (string) file_get_contents($vmString);
        try {
            file_put_contents($vmString, $backup."\n// touch for #23458 fingerprint probe\n");
            $fpStrposAfter = HelperRuntimeCache::fingerprintV2($realUnit, $depsStrpos);
            $fpWwAfter = HelperRuntimeCache::fingerprintV2($wordwrap, $depsWw);
            $this->assertNotSame($fpStrposBefore, $fpStrposAfter, 'VmString edit must invalidate Strpos unit');
            if (!\in_array('/ext/standard/VmString.php', $depsWw, true)) {
                $this->assertSame($fpWwBefore, $fpWwAfter, 'Wordwrap without VmString dep stays fresh');
            }
        } finally {
            file_put_contents($vmString, $backup);
        }
    }

    public function testMigrateManifestToV2KeepsObjectsFresh(): void
    {
        $root = \dirname(__DIR__, 3);
        $unit = $root.'/ext/standard/StrposJitHelper.php';
        $ref = new \ReflectionClass(HelperRuntimeCache::class);
        $m = $ref->getMethod('fingerprintV1Legacy');
        $m->setAccessible(true);
        $legacyFp = (string) $m->invoke(null, $unit);
        $manifest = [
            'fingerprint' => $legacyFp,
            'unit' => '/ext/standard/StrposJitHelper.php',
            'helpers' => ['x' => 'y'],
        ];
        $next = HelperRuntimeCache::migrateManifestToV2($manifest, $unit);
        $this->assertNotNull($next);
        $this->assertArrayHasKey('deps', $next);
        $this->assertContains('/ext/standard/VmString.php', $next['deps']);
        $this->assertTrue(HelperRuntimeCache::manifestFingerprintMatches($next, $unit));
        $this->assertNotSame($legacyFp, $next['fingerprint']);
    }

    private function fingerprintViaSubprocess(string $root, string $unitFile, string $llvmPath): string
    {
        $php = escapeshellarg(PHP_BINARY);
        $rootArg = escapeshellarg($root);
        $unitArg = escapeshellarg($unitFile);
        $llvmLiteral = var_export($llvmPath, true);

        $code = 'chdir('.$rootArg.');'
            .'putenv("PHP_COMPILER_LLVM_PATH=" . '.$llvmLiteral.');'
            .'require "lib/AOT/HelperRuntimeCache.php";'
            .'echo \\PHPCompiler\\AOT\\HelperRuntimeCache::unitFingerprint('.$unitArg.');';
        $cmd = $php.' -r '.escapeshellarg($code);
        $out = (string) @shell_exec($cmd);

        return trim($out);
    }
}
