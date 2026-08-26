<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop Context::ensureFullStandaloneBodies always-on ExceptionBridge / ErrorBridge
 * NestedJIT + dead string-helpers gate (#35099 / peers #34732 / #34769 / #35089).
 *
 * Full standalone must not NestedJIT type_error_* / error_* during init (#32122 .1 mint).
 * Standalone {main} already ensureLinked / emitClear|emitAbort → ensureLinked before lookup.
 */
final class ContextFullStandaloneLazyExceptionErrorBridgeRuntimeShrinkTest extends TestCase
{
    public function testEnsureFullDropsEagerExceptionAndErrorBridge(): void
    {
        $context = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringContainsString('#35099', $context);
        $fullPos = strpos($context, 'private function ensureFullStandaloneBodies');
        $this->assertNotFalse($fullPos);
        $fullEnd = strpos($context, 'public function compileToFile', $fullPos);
        $this->assertNotFalse($fullEnd);
        $fullBody = substr($context, $fullPos, $fullEnd - $fullPos);

        foreach ([
            'ExceptionBridge::ensureStandaloneBodies($this)',
            'ErrorBridge::ensureStandaloneBodies($this)',
            'StringSoundex::ensureStandaloneBodies($this)',
            'StringQuotemeta::ensureStandaloneBodies($this)',
            'StringPregQuote::ensureStandaloneBodies($this)',
            'StringNl2br::ensureStandaloneBodies($this)',
            'StringUcwords::ensureStandaloneBodies($this)',
            'StringMetaphone::ensureStandaloneBodies($this)',
            'StringWordwrap::ensureStandaloneBodies($this)',
            'MbNumericEntity::ensureStandaloneBodies($this)',
            'StringBin2hex::ensureStandaloneBodies($this)',
            'StringBase64Encode::ensureStandaloneBodies($this)',
            'StringBase64Decode::ensureStandaloneBodies($this)',
            'StringStrrev::ensureStandaloneBodies($this)',
            'StringStrRepeat::ensureStandaloneBodies($this)',
            'StringStrPad::ensureStandaloneBodies($this)',
            'StringStrRot13::ensureStandaloneBodies($this)',
            'StringUniqid::ensureStandaloneBodies($this)',
            'StringChunkSplit::ensureStandaloneBodies($this)',
            'StringGraphemeStrSplit::ensureStandaloneBodies($this)',
            'StringHex2bin::ensureStandaloneBodies($this)',
            'StringLevenshtein::ensureStandaloneBodies($this)',
            'StringSubstrCount::ensureStandaloneBodies($this)',
            'StringCountChars::ensureStandaloneBodies($this)',
            'StringNCompare::ensureStandaloneBodies($this)',
            'StringStrWordCount::ensureStandaloneBodies($this)',
            'StringStripTags::ensureStandaloneBodies($this)',
            'StringStrtr::ensureStandaloneBodies($this)',
            'StringParseStr::ensureStandaloneBodies($this)',
        ] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                $fullBody,
                'ensureFullStandaloneBodies must not eagerly '.$forbidden.' (#35099)'
            );
        }

        // Still links echo / argv / refresh used by standalone main.
        $this->assertStringContainsString('ValueEchoRuntime::ensureLinked($this)', $fullBody);
        $this->assertStringContainsString('CliArgvRuntime::ensureStandaloneBodies($this)', $fullBody);
        $this->assertStringContainsString('SuperglobalRefreshRuntime::ensureStandaloneBodies($this)', $fullBody);
        $this->assertStringContainsString('StringFormat::ensureStandaloneBodies($this)', $fullBody);
    }

    public function testStandaloneMainStillEnsuresBeforeClearAbort(): void
    {
        $context = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringContainsString('ErrorBridge::ensureLinked($this)', $context);
        $this->assertStringContainsString('ErrorBridge::emitClearForStandaloneMain($this)', $context);
        $this->assertStringContainsString('ExceptionBridge::emitClearForStandaloneMain($this)', $context);
        $this->assertStringContainsString('ExceptionBridge::emitAbortIfPendingForStandaloneMain($this)', $context);

        foreach ([
            'lib/JIT/Builtin/TypeErrorRaise.php',
            'lib/JIT/Builtin/ErrorRaise.php',
        ] as $rel) {
            $source = (string) file_get_contents(__DIR__.'/../../'.$rel);
            foreach (['emitClearForStandaloneMain', 'emitAbortIfPendingForStandaloneMain'] as $method) {
                $pos = strpos($source, 'public static function '.$method);
                $this->assertNotFalse($pos, $rel.' '.$method);
                $next = strpos($source, 'public static function ', $pos + 10);
                $body = false === $next
                    ? substr($source, $pos)
                    : substr($source, $pos, $next - $pos);
                $this->assertStringContainsString(
                    'self::ensureLinked($context)',
                    $body,
                    $rel.'::'.$method.' must ensureLinked before lookup (#35099)'
                );
            }
        }
    }

    public function testCallSitesStillEnsureStringHelpers(): void
    {
        $checks = [
            'ext/standard/soundex.php' => 'StringSoundex::ensureLinked',
            'ext/standard/quotemeta.php' => 'StringQuotemeta::ensureLinked',
            'ext/standard/preg_quote.php' => 'StringPregQuote::ensureLinked',
            'ext/standard/nl2br.php' => 'StringNl2br::ensureLinked',
            'ext/standard/ucwords.php' => 'StringUcwords::ensureLinked',
            'ext/standard/metaphone.php' => 'StringMetaphone::ensureLinked',
            'ext/standard/wordwrap.php' => 'StringWordwrap::ensureLinked',
            'ext/standard/bin2hex.php' => 'StringBin2hex::ensureLinked',
            'ext/standard/base64_encode.php' => 'StringBase64Encode::ensureLinked',
            'ext/standard/strrev.php' => 'StringStrrev::ensureLinked',
        ];
        foreach ($checks as $rel => $needle) {
            $path = __DIR__.'/../../'.$rel;
            $this->assertFileExists($path, $rel);
            $source = (string) file_get_contents($path);
            $this->assertStringContainsString($needle, $source, $rel.' must ensure lazily (#35099)');
        }
    }

    public function testNoNewRuntimeCForFullExceptionErrorBridgeLazy(): void
    {
        $runtimeDir = dirname(__DIR__, 2).'/lib/AOT/runtime';
        foreach ([
            'phpc_type_error_raise.c',
            'phpc_error_raise.c',
            'exception_bridge.c',
            'error_bridge.c',
            'string_soundex.c',
        ] as $name) {
            $this->assertFileDoesNotExist(
                $runtimeDir.'/'.$name,
                "must not add {$name} for #35099 — PHP JIT bridges only"
            );
        }
    }
}
