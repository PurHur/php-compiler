<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** @group aot-lint */
final class BootstrapSelfhostBundleTest extends TestCase
{
    private static string $root;

    /** @var list<string> */
    private const LIB_SPINE_SMOKE_NEW_UNITS = [
        'lib/JIT/Builtin/Type.php',
        'lib/JIT/Builtin/Type/String_.php',
        'lib/Doctor.php',
        'lib/Cli/InvokeCwd.php',
        'lib/Cli/PhpcBuild.php',
        'lib/BuiltinParamNames.php',
        'lib/VM/NamedArgs.php',
        'ext/types/is_type.php',
        'ext/types/strlen.php',
        'ext/types/mb_strlen.php',
        'ext/types/JitMbStrlen.php',
        'ext/types/Module.php',
        'lib/Cli/PhpcInit.php',
        'lib/Web/CgiAotDriver.php',
        'lib/Web/CgiDriver.php',
        'lib/Web/ProjectDeploy.php',
        'lib/Web/DevServer.php',
        'lib/Web/ManifestValidator.php',
        'lib/Web/Params.php',
        'lib/Web/ProjectAutoload.php',
        'lib/Web/ProjectBootstrap.php',
        'lib/Web/ProjectManifest.php',
        'lib/Web/ResponseContext.php',
        'lib/JIT/Builtin/CallArgv.php',
        'lib/JIT/Builtin/IniSet.php',
        'lib/JIT/Builtin/SessionId.php',
        'lib/JIT/Builtin/SessionName.php',
        'lib/JIT/Builtin/StringFunctionExists.php',
        'lib/JIT/Builtin/StringHttpBuildQuery.php',
        'lib/JIT/Builtin/StringSerialize.php',
        'lib/JIT/Builtin/StringSuperglobalName.php',
        'lib/JIT/ErrorHandlerCallbackPolicy.php',
        'lib/JIT/UsortCallbackPolicy.php',
        'lib/JIT/PregReplaceCallbackPolicy.php',
        'lib/JIT/ArrayReduceCallbackPolicy.php',
        'lib/JIT/ArrayMapCallbackPolicy.php',
        'lib/JIT/TypeCheck.php',
        'lib/JIT/ReflectionBuiltinHelper.php',
        'lib/JIT/ArrayBuiltinHelper.php',
        'lib/JIT/UnsetHelper.php',
        'lib/JIT/VarFetchHelper.php',
        'lib/JIT/Builtin/Type/HashTable.php',
        'lib/JIT/Builtin/Type/Object_.php',
        'lib/JIT/Builtin/StringParseStr.php',
        'lib/JIT/Builtin/StringPregMatch.php',
        'lib/JIT/Builtin/StringUnserialize.php',
        'lib/JIT/Builtin/StringUrldecode.php',
        'ext/standard/JitAddslashes.php',
        'ext/standard/JitBase64Encode.php',
        'ext/standard/JitBin2hex.php',
        'ext/standard/JitChunkSplit.php',
        'ext/standard/JitCrc32.php',
        'ext/standard/JitExplode.php',
        'ext/standard/JitChmod.php',
        'ext/standard/JitCopy.php',
        'ext/standard/JitDate.php',
        'ext/standard/JitImplode.php',
        'ext/standard/JitNl2br.php',
        'ext/standard/JitPregQuote.php',
        'ext/standard/JitQuotemeta.php',
        'ext/standard/JitStrRot13.php',
        'ext/standard/JitSessionId.php',
        'ext/standard/JitSessionName.php',
        'ext/standard/JitChdir.php',
        'ext/standard/JitClassExists.php',
        'ext/standard/JitClearstatcache.php',
        'ext/standard/JitDeployPath.php',
        'ext/standard/JitEnumExists.php',
        'ext/standard/JitEnv.php',
        'ext/standard/JitFclose.php',
        'ext/standard/JitFeof.php',
        'ext/standard/JitFgetc.php',
        'ext/standard/JitFgetcsv.php',
        'ext/standard/JitFgets.php',
        'ext/standard/JitFopen.php',
        'ext/standard/JitFpassthru.php',
        'ext/standard/JitFputcsv.php',
        'ext/standard/JitFread.php',
        'ext/standard/JitFseek.php',
        'ext/standard/JitFileGetContents.php',
        'ext/standard/JitFilter.php',
        'ext/standard/JitFunctionExists.php',
        'ext/standard/JitGetcwd.php',
        'ext/standard/JitHash.php',
        'ext/standard/JitHex2bin.php',
        'ext/standard/JitHtmlspecialchars.php',
        'ext/standard/JitIni.php',
        'ext/standard/JitJsonDecode.php',
        'ext/standard/JitJsonEncode.php',
        'ext/standard/JitJsonLastError.php',
        'ext/standard/JitNumberFormat.php',
        'ext/standard/JitPregMatch.php',
        'ext/standard/JitRealpath.php',
        'ext/standard/JitSerialize.php',
        'ext/standard/JitSprintf.php',
        'ext/standard/JitStrPad.php',
        'ext/standard/JitStrRepeat.php',
        'ext/standard/JitStrReplace.php',
        'ext/standard/JitStrShuffle.php',
        'ext/standard/JitStrSplit.php',
        'ext/standard/JitStringConcat.php',
        'ext/standard/JitStringIndex.php',
        'ext/standard/JitStripTags.php',
        'ext/standard/JitStripslashes.php',
        'ext/standard/JitStrpbrk.php',
        'ext/standard/JitStrpos.php',
        'ext/standard/JitStrrchr.php',
        'ext/standard/JitStrrpos.php',
        'ext/standard/JitStrstr.php',
        'ext/standard/JitSubstrCount.php',
        'ext/standard/JitStrtr.php',
        'ext/standard/JitSuperglobalName.php',
        'ext/standard/JitTempnam.php',
        'ext/standard/JitUnserialize.php',
        'ext/standard/JitUrlencode.php',
        'ext/standard/JitWordwrap.php',
        'ext/standard/JitFflush.php',
        'ext/standard/JitFilePutContents.php',
        'ext/standard/JitFilemtime.php',
        'ext/standard/JitFileperms.php',
        'ext/standard/JitFilesize.php',
        'ext/standard/JitFiletype.php',
        'ext/standard/JitFsGlob.php',
        'ext/standard/JitFtell.php',
        'ext/standard/JitFwrite.php',
        'ext/standard/JitMkdir.php',
        'ext/standard/JitPath.php',
        'ext/standard/JitPathinfo.php',
        'ext/standard/JitReadfile.php',
        'ext/standard/JitReadlink.php',
        'ext/standard/JitRename.php',
        'ext/standard/JitRequestBody.php',
        'ext/standard/JitRmdir.php',
        'ext/standard/JitShellExec.php',
        'ext/standard/JitStat.php',
        'ext/standard/JitStatArray.php',
        'ext/standard/JitSysGetTempDir.php',
        'ext/standard/JitTouch.php',
        'ext/standard/JitUnlink.php',
        'ext/standard/JitWebParams.php',
        'ext/standard/Module.php',
    ];

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(__DIR__, 2);
    }

    public function testCompilerMinimalBundleUnitCount(): void
    {
        $entry = self::$root.'/test/selfhost/compiler_minimal/main.php';
        $this->assertFileExists($entry);
        $count = substr_count((string) file_get_contents($entry), 'require_once __DIR__');
        $this->assertSame(108, $count);
    }

    public function testCompilerLibSpineSmokeBundleUnitCountAndKeyUnits(): void
    {
        $entry = self::$root.'/test/selfhost/compiler_lib_spine_smoke/main.php';
        $this->assertFileExists($entry);
        $contents = (string) file_get_contents($entry);
        $count = substr_count($contents, 'require_once __DIR__');
        $this->assertSame(253, $count, '108 compiler_minimal units + 145 M2 spine units');
        foreach (self::LIB_SPINE_SMOKE_NEW_UNITS as $unit) {
            $this->assertStringContainsString(
                "require_once __DIR__.'/../../../{$unit}';",
                $contents,
                "missing {$unit}"
            );
        }
    }

    public function testCompilerLibSpineSmokePassesAotLint(): void
    {
        $entry = self::$root.'/test/selfhost/compiler_lib_spine_smoke/main.php';
        $prefix = LlvmToolchain::envPrefix(self::$root);
        $cmd = implode(' ', array_map('escapeshellarg', [
            ...$prefix,
            'php',
            self::$root.'/bin/compile.php',
            '-l',
            $entry,
        ])).' 2>&1';
        exec($cmd, $lines, $exitCode);
        $this->assertSame(0, $exitCode, implode("\n", $lines));
    }
}
