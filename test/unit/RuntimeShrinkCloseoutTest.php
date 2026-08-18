<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * php-in-php closeout guard (#5211, #1492): open queue issues #5200–#5708 C TUs deleted; LLVM/PHP SSOT in ext/ + lib/JIT/.
 *
 * @group aot-lint
 */
final class RuntimeShrinkCloseoutTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
    }

    /** @return list<string> */
    private function deletedAotRuntimeSources(): array
    {
        return [
            'phpc_settype.c',        // #5200
            'phpc_dir.c',            // #5258 / #5494
            'phpc_fs_dir.c',         // #5258 / #6982
            'phpc_unserialize.c',    // #5280 / #5991
            'phpc_string_cslashes.c', // #5278 / #5652
            'phpc_class_methods.c', // #5290
            'phpc_ini_set.c',        // #5363 / #5736
            'phpc_json_decode.c',    // #5302 / #6202
            'hash_crypto.c',         // #5227 / #7189
            'password_crypto.c',     // #5234 / #5708 / #6906
            'superglobals_refresh.c', // #5330
            'phpc_stream.c',         // #5343 / #6821
            'preg_match.c',          // #5289
        ];
    }

    public function testDeletedPhpInPhpRuntimeSourcesAbsent(): void
    {
        $runtimeDir = $this->repoRoot.'/lib/AOT/runtime';
        $linker = (string) file_get_contents($this->repoRoot.'/lib/AOT/Linker.php');
        $jitBuiltinDir = $this->repoRoot.'/lib/JIT/Builtin';

        foreach ($this->deletedAotRuntimeSources() as $basename) {
            $this->assertFileDoesNotExist(
                $runtimeDir.'/'.$basename,
                "{$basename} must stay deleted (php-in-php migration)"
            );
            $this->assertFileDoesNotExist(
                $jitBuiltinDir.'/'.$basename,
                "lib/JIT/Builtin/{$basename} must not reappear"
            );
            $this->assertStringNotContainsString(
                $basename,
                $linker,
                "Linker must not list {$basename}"
            );
        }
    }

    public function testPhpReplacementsPresent(): void
    {
        $checks = [
            'ext/standard/JitSettype.php' => 'JitSettype',
            'ext/standard/SettypeJitHelper.php' => 'SettypeJitHelper',
            'lib/JIT/Builtin/SettypeRuntime.php' => 'SettypeRuntime',
            'ext/standard/VmSerialize.php' => 'resolveEnumCaseVariable',
            'ext/standard/VmUnserializeFormat.php' => 'decodePayload',
            'lib/JIT/Builtin/StringUnserialize.php' => 'UnserializeJitHelper',
            'lib/JIT/Builtin/IniRuntime.php' => 'IniJitHelper',
            'lib/JIT/Builtin/IniIntrospectionRuntime.php' => 'IniIntrospectionJitHelper',
            'lib/JIT/Builtin/StringJsonDecode.php' => 'JsonDecodeJitHelper',
            'lib/JIT/Builtin/PasswordCryptoRuntime.php' => 'password_hash',
            'lib/JIT/Builtin/StringHashCryptoPhp.php' => 'HashCryptoJitHelper',
            'lib/JIT/Builtin/StringDirRuntime.php' => 'DirHandleJitHelper',
            'lib/JIT/Builtin/PregMatchRuntime.php' => 'PregJitHelper',
            'lib/JIT/Builtin/StringPregMatchJit.php' => 'PregMatchRuntime',
            'lib/JIT/Builtin/GzStreamRuntime.php' => 'GzStreamJitHelper',
            'ext/standard/GzStreamJitHelper.php' => 'VmGzStream',
            'lib/JIT/Builtin/StringFsDirJit.php' => 'StringFsDirJit',
            'ext/standard/stripcslashes.php' => 'VmString',
            'lib/JIT/Builtin/OpensslSignRuntime.php' => 'OpensslSignJitHelper',
            'lib/JIT/Builtin/OpensslEncryptRuntime.php' => 'OpensslEncryptJitHelper',
            'lib/JIT/Builtin/OpensslDigestRuntime.php' => 'OpensslDigestJitHelper',
            'lib/JIT/Builtin/OpensslPbkdf2Runtime.php' => '__compiler_hash',
            'lib/JIT/Builtin/OpensslMethodsRuntime.php' => 'OpensslMethodsJitHelper',
            'ext/openssl/OpensslMethodsJitHelper.php' => 'OpensslCipherRegistry::CIPHER_METHODS',
            'lib/JIT/Builtin/LibcryptRuntime.php' => 'LibcryptJitHelper',
            'ext/standard/LibcryptJitHelper.php' => 'cryptArgv',
        ];

        foreach ($checks as $relativePath => $needle) {
            $path = $this->repoRoot.'/'.$relativePath;
            $this->assertFileExists($path, "{$relativePath} must exist as PHP SSOT");
            $source = (string) file_get_contents($path);
            $this->assertStringContainsString($needle, $source, "{$relativePath} must implement {$needle}");
        }
    }

    public function testNoAotRuntimeCSourcesRemain(): void
    {
        $runtimeDir = $this->repoRoot.'/lib/AOT/runtime';
        $cFiles = glob($runtimeDir.'/*.c') ?: [];
        sort($cFiles);
        $this->assertSame([], $cFiles, 'No hand-written C runtime TUs may remain under lib/AOT/runtime/');
        $this->assertFileDoesNotExist($runtimeDir.'/openssl_ev.c', 'openssl_ev.c deleted — OpensslSignJitHelper PHP (#16454)');
        $this->assertFileDoesNotExist($this->repoRoot.'/lib/JIT/Builtin/LibcryptThinRuntime.php', 'LibcryptThinRuntime deleted — LibcryptRuntime PHP (#9275)');
        $this->assertFileDoesNotExist($this->repoRoot.'/lib/JIT/Builtin/runtime/openssl_ev.c');
    }
}
