<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop Context::ensureMinimalUserStandaloneBodies always-on stdlib NestedJIT (#34578 / peer #34566).
 *
 * Thin AOT hello-world must not NestedJIT file/hash/ini/meta/… ABIs; call sites
 * ensureLinked / ensureStandaloneBodies / emit* / invoke* lazily (#32122 .1 mint class).
 */
final class ContextMinimalStandaloneLazyStdlibRuntimeShrinkTest extends TestCase
{
    public function testEnsureMinimalDropsEagerStdlibBatch(): void
    {
        $context = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringContainsString('#34578', $context);
        $minimalPos = strpos($context, 'private function ensureMinimalUserStandaloneBodies');
        $this->assertNotFalse($minimalPos);
        $minimalEnd = strpos($context, 'private function ensureBootstrapAotStandaloneBodies', $minimalPos);
        $this->assertNotFalse($minimalEnd);
        $minimalBody = substr($context, $minimalPos, $minimalEnd - $minimalPos);

        foreach ([
            'StringRandomBytes::implement($this)',
            'StringUtf8Latin1::ensureStandaloneBodies($this)',
            'RewriteVarsRuntime::ensureStandaloneBodies($this)',
            'DefineRuntime::ensureStandaloneBodies($this)',
            'StringStrContains::ensureStandaloneBodies($this)',
            'StatPathRuntime::ensureStandaloneBodies($this)',
            'StringFileGetContents::ensureStandaloneBodies($this)',
            'MetaTagsRuntime::ensureStandaloneBodies($this)',
            'StringHashCrypto::ensureStandaloneBodies($this)',
            'MbNumericEntity::ensureStandaloneBodies($this)',
            'StringReadfile::ensureStandaloneBodies($this)',
            'StringBin2hex::ensureStandaloneBodies($this)',
            'StringAddslashes::ensureStandaloneBodies($this)',
            'StringStripslashes::ensureStandaloneBodies($this)',
            'StringFilePutContents::ensureStandaloneBodies($this)',
            'IniRuntime::ensureLinked($this)',
        ] as $needle) {
            $this->assertStringNotContainsString(
                $needle,
                $minimalBody,
                "ensureMinimalUserStandaloneBodies must not eagerly {$needle} (#34578)"
            );
        }

        // Essentials for thin argv / is_superglobal stay (#34807 dropped EnvLocal;
        // #34641 dropped StringTriggerError).
        foreach ([
            'CliArgvRuntime::ensureStandaloneBodies($this)',
            'SuperglobalNameRuntime::ensureLinked($this)',
        ] as $keep) {
            $this->assertStringContainsString($keep, $minimalBody, "keep {$keep} in minimal (#34578)");
        }
        $this->assertStringNotContainsString(
            'ObOutputRuntime::ensureLinked($this)',
            $minimalBody,
            'ensureMinimal must not eagerly ObOutputRuntime (#34695)'
        );
        $this->assertStringNotContainsString(
            'StringTriggerError::ensureStandaloneBodies($this)',
            $minimalBody,
            'ensureMinimal must not eagerly StringTriggerError (#34641)'
        );
    }

    public function testCallSitesEnsureBeforeLookup(): void
    {
        $checks = [
            'ext/standard/JitFileGetContents.php' => 'StringFileGetContents::ensureLinked',
            'ext/standard/JitGetMetaTags.php' => 'MetaTagsRuntime::ensureLinked',
            'ext/standard/JitHash.php' => 'StringHashCrypto::ensureLinked',
            'ext/standard/JitUtf8Latin1.php' => 'StringUtf8Latin1::ensureLinked',
            'ext/standard/JitDefine.php' => 'DefineRuntime::ensureLinked',
            'ext/standard/JitIni.php' => 'IniRuntime::ensureLinked',
            'ext/standard/JitFilePutContents.php' => 'StringFilePutContents::ensureLinked',
            'ext/standard/readfile.php' => 'StringReadfile::ensureLinked',
            'ext/standard/bin2hex.php' => 'StringBin2hex::ensureLinked',
            'ext/standard/addslashes.php' => 'StringAddslashes::ensureLinked',
            'ext/standard/stripslashes.php' => 'StringStripslashes::ensureLinked',
            'ext/standard/JitRandomBytes.php' => 'StringRandomBytes::ensureLinked',
            'ext/standard/JitStat.php' => 'StatPathRuntime::ensureLinked',
            'ext/mbstring/JitMbNumericEntity.php' => 'MbNumericEntity::ensureLinked',
            'lib/JIT/Builtin/RewriteVarsRuntime.php' => 'self::ensureLinked($context)',
            'lib/JIT/Builtin/StringStrContains.php' => 'self::ensureMemcmp($context)',
        ];
        foreach ($checks as $rel => $needle) {
            $path = __DIR__.'/../../'.$rel;
            $this->assertFileExists($path, $rel);
            $source = (string) file_get_contents($path);
            $this->assertStringContainsString($needle, $source, $rel.' must ensure lazily (#34578)');
        }
    }

    public function testNoNewRuntimeCForMinimalStdlibLazy(): void
    {
        $runtimeDir = dirname(__DIR__, 2).'/lib/AOT/runtime';
        foreach ([
            'file_get_contents.c',
            'get_meta_tags.c',
            'hash_crypto.c',
            'ini_runtime.c',
            'random_bytes.c',
        ] as $name) {
            $this->assertFileDoesNotExist(
                $runtimeDir.'/'.$name,
                "must not add {$name} for #34578 — PHP JIT bridges only"
            );
        }
    }
}
