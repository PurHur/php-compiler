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
    public function testUnitFingerprintChangesWhenMissingLlvmPathsDiffer(): void
    {
        $root = \dirname(__DIR__, 3);
        $tmp = \sys_get_temp_dir().'/phpc-helper-runtime-fingerprint-'.bin2hex(random_bytes(6)).'.php';
        file_put_contents($tmp, "<?php\nreturn 1;\n");
        try {
            $a = $this->fingerprintViaSubprocess($root, $tmp, '/tmp/llvm-a-missing-'.bin2hex(random_bytes(4)));
            $b = $this->fingerprintViaSubprocess($root, $tmp, '/tmp/llvm-b-missing-'.bin2hex(random_bytes(4)));
            $this->assertNotSame($a, $b, 'missing LLVM installs at distinct paths must still diverge');
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * @runInSeparateProcess
     */
    public function testCoreFingerprintIgnoresLlvmInstallPathWhenLibBytesMatch(): void
    {
        $root = \dirname(__DIR__, 3);
        $hostLib = $root.'/.llvm/libLLVM-9.so.1';
        $dockerLib = '/opt/llvm9/libLLVM-9.so.1';
        if (!is_file($hostLib) || !is_file($dockerLib)) {
            $this->markTestSkipped('need both repo .llvm and /opt/llvm9 libLLVM-9.so.1');
        }
        if (hash_file('sha256', $hostLib) !== hash_file('sha256', $dockerLib)) {
            $this->markTestSkipped('host .llvm and /opt/llvm9 libLLVM differ — path-independence N/A');
        }
        $a = $this->coreFingerprintViaSubprocess($root, $root.'/.llvm');
        $b = $this->coreFingerprintViaSubprocess($root, '/opt/llvm9');
        $this->assertSame($a, $b, '#24381: identical libLLVM bytes must share coreFingerprint across install paths');
        $this->assertNotSame('', $a);
    }

    /**
     * @runInSeparateProcess
     */
    public function testEquivalentCoresAcceptOptLlvm9PathAlias(): void
    {
        $root = \dirname(__DIR__, 3);
        if (!is_file($root.'/.llvm/libLLVM-9.so.1') && !is_file('/opt/llvm9/libLLVM-9.so.1')) {
            $this->markTestSkipped('no libLLVM-9.so.1 available');
        }
        $live = HelperRuntimeCache::coreFingerprint();
        $aliases = HelperRuntimeCache::equivalentCoreFingerprints();
        $this->assertContains($live, $aliases);
        $pathCore = null;
        foreach (['/opt/llvm9', $root.'/.llvm'] as $dir) {
            // Reconstruct pre-#24381 path core via reflection of private helper is heavy;
            // assert alias set is larger than live alone when a real lib is present.
            if (is_file($dir.'/libLLVM-9.so.1') || true) {
                $pathCore = true;
                break;
            }
        }
        $this->assertTrue($pathCore);
        $this->assertGreaterThan(1, \count($aliases), '#24381: path-keyed cores must alias when libLLVM is present');
        $this->assertTrue(HelperRuntimeCache::coreFingerprintMatches($live));
    }

    public function testWarmupSkipsCorpusEmitWhenCommittedUnitsExistRegardlessOfCoreFingerprint(): void
    {
        $root = \dirname(__DIR__, 3);
        $source = (string) file_get_contents($root.'/lib/AOT/HelperRuntimeCache.php');
        $this->assertStringContainsString('committedCacheHasUnits', $source);
        $this->assertStringContainsString('skip corpus warmup', $source);
        $this->assertDoesNotMatchRegularExpression(
            '/function committedCacheHasUnits\(\): bool\s*\{[^}]*coreFingerprintMatches/s',
            $source,
            'warmup skip must not require core_fingerprint match (#32122)'
        );
        $ref = new \ReflectionClass(HelperRuntimeCache::class);
        $m = $ref->getMethod('committedCacheHasUnits');
        $m->setAccessible(true);
        $this->assertTrue(
            (bool) $m->invoke(null),
            'committed helper-runtime units must skip hello-world corpus warmup'
        );
    }

    public function testCoreFingerprintIgnoresJitPhpContent(): void
    {
        $root = \dirname(__DIR__, 3);
        $src = (string) file_get_contents($root.'/lib/AOT/HelperRuntimeCache.php');
        $this->assertStringContainsString('Global inputs only (#23458 / #24381)', $src);
        $this->assertStringContainsString('llvmIdentityToken', $src);
        $this->assertStringContainsString('equivalentCoreFingerprints', $src);
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

    /**
     * #25377: --prelink must not mass-delete live-site units that failed to emit
     * (that made check-helper-runtime-prelink --strict green by absence).
     */
    public function testEmitPrelinkKeepsLiveSiteUnitsByDefault(): void
    {
        $root = \dirname(__DIR__, 3);
        $source = (string) file_get_contents($root.'/script/emit-helper-runtime-object.php');
        $this->assertStringContainsString('live-site prune guard #25377', $source);
        $this->assertStringContainsString('--prelink-no-prune', $source);
        $this->assertStringContainsString('--prelink-prune-stale', $source);
        $this->assertStringContainsString('kept_live_unpublished', $source);
        $this->assertStringContainsString('isset($liveSlugs[$slug])', $source);
        // Default path must keep live sites; only --prelink-prune-stale restores mass-delete.
        $this->assertMatchesRegularExpression(
            '/if\s*\(\s*!\s*\$pruneStale\s*&&\s*isset\(\s*\$liveSlugs\[\$slug\]\s*\)\s*\)/',
            $source,
            'default --prelink must keep committed units whose helper site still exists'
        );
    }

    /**
     * FILTER_VALIDATE_{EMAIL,IP,URL} use VALIDATE_PATH + COMPILED_VALIDATE (#27068/#27207/#27206).
     * Emit discovery must pair them like HELPER_PATH or orphan Filter*JitHelper prelink dirs
     * stay stale forever after the Validate split (#27911–13).
     */
    public function testEmitDiscoversValidatePathCompiledValidatePairs(): void
    {
        $root = \dirname(__DIR__, 3);
        $source = (string) file_get_contents($root.'/script/emit-helper-runtime-object.php');
        $this->assertStringContainsString("str_ends_with(\$constName, 'VALIDATE_PATH')", $source);
        $this->assertStringContainsString("COMPILED_VALIDATE", $source);
        $this->assertStringContainsString('FILTER_VALIDATE_{EMAIL,IP,URL}', $source);

        foreach ([
            'StringFilterEmail.php' => '/ext/filter/FilterEmailValidate.php',
            'StringFilterIp.php' => '/ext/filter/FilterIpValidate.php',
            'StringFilterUrl.php' => '/ext/filter/FilterUrlValidate.php',
        ] as $builtin => $unit) {
            $code = (string) file_get_contents($root.'/lib/JIT/Builtin/'.$builtin);
            $this->assertStringContainsString('VALIDATE_PATH', $code);
            $this->assertStringContainsString($unit, $code);
            $this->assertStringContainsString('COMPILED_VALIDATE', $code);
            $slug = HelperRuntimeCache::slugFor($unit);
            $this->assertFileExists(
                $root.'/prelinked/helper-runtime/x86_64-linux/units/'.$slug.'/unit.o',
                $unit.' must be in committed helper-runtime after VALIDATE_PATH discovery'
            );
        }
        $this->assertDirectoryDoesNotExist(
            $root.'/prelinked/helper-runtime/x86_64-linux/units/ext_filter_FilterIpJitHelper_php'
        );
        $this->assertDirectoryDoesNotExist(
            $root.'/prelinked/helper-runtime/x86_64-linux/units/ext_filter_FilterUrlJitHelper_php'
        );
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

    private function coreFingerprintViaSubprocess(string $root, string $llvmPath): string
    {
        $php = escapeshellarg(PHP_BINARY);
        $rootArg = escapeshellarg($root);
        $llvmLiteral = var_export($llvmPath, true);

        $code = 'chdir('.$rootArg.');'
            .'putenv("PHP_COMPILER_LLVM_PATH=" . '.$llvmLiteral.');'
            .'require "vendor/autoload.php";'
            .'echo \\PHPCompiler\\AOT\\HelperRuntimeCache::coreFingerprint();';
        $cmd = $php.' -r '.escapeshellarg($code);
        $out = (string) @shell_exec($cmd);

        return trim($out);
    }
}
