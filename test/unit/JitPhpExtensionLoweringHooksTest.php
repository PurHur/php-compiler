<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Guard: lib/JIT.php must not import SimpleXML/XMLReader/XMLWriter/DOM helpers
 * after Module::jitInit registration (#36204).
 */
final class JitPhpExtensionLoweringHooksTest extends TestCase
{
    public function testJitPhpDoesNotImportMovedUserScriptHelpers(): void
    {
        $src = file_get_contents(dirname(__DIR__, 2).'/lib/JIT.php');
        $this->assertNotFalse($src);
        foreach ([
            'JitSimpleXmlUserScript',
            'JitXmlReaderUserScript',
            'JitXmlWriterUserScript',
            'JitXsltUserScript',
            'JitMbNumericEntity',
            'JitDomElementTextContent',
            'JitDomImportSimpleXmlUserScript',
            'JitDomLoadXMLUserScript::setPendingLoadXmlReceiverVarName',
        ] as $needle) {
            $this->assertStringNotContainsString(
                $needle,
                $src,
                'lib/JIT.php still references '.$needle.' — register via ExtensionLoweringHooks'
            );
        }
        $this->assertStringContainsString(
            'extensionLowering',
            $src,
            'lib/JIT.php must dispatch via Context::$extensionLowering'
        );
    }

    public function testJitPhpDoesNotImportDomCompileTimeHelpers(): void
    {
        $src = file_get_contents(dirname(__DIR__, 2).'/lib/JIT.php');
        $this->assertNotFalse($src);
        $stripped = (string) preg_replace('~/\*.*?\*/~s', '', $src);
        $stripped = (string) preg_replace('~//.*$~m', '', $stripped);
        $this->assertStringNotContainsString(
            '\\PHPCompiler\\ext\\dom\\',
            $stripped,
            'lib/JIT.php still imports ext\\dom — use DomCompileTimeHooks'
        );
        $this->assertStringContainsString(
            'domCompileTime',
            $src,
            'lib/JIT.php must dispatch DOM compile-time stamps via ExtensionLoweringHooks'
        );
        $this->assertStringContainsString(
            'new JitDomCompileTimeFacade()',
            (string) file_get_contents(dirname(__DIR__, 2).'/ext/dom/Module.php'),
            'ext/dom Module::jitInit must register JitDomCompileTimeFacade'
        );
    }

    public function testJitPhpDoesNotImportMbstringOrXslHelpers(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2).'/lib/JIT.php');
        $stripped = (string) preg_replace('~/\*.*?\*/~s', '', $src);
        $stripped = (string) preg_replace('~//.*$~m', '', $stripped);
        $this->assertStringNotContainsString(
            '\\PHPCompiler\\ext\\mbstring\\',
            $stripped,
            'lib/JIT.php still imports ext\\mbstring — use ExtensionLoweringHooks'
        );
        $this->assertStringNotContainsString(
            '\\PHPCompiler\\ext\\xsl\\',
            $stripped,
            'lib/JIT.php still imports ext\\xsl — use ExtensionLoweringHooks'
        );
        $this->assertStringContainsString(
            'foldMbNumericEntityHook',
            (string) file_get_contents(dirname(__DIR__, 2).'/ext/mbstring/Module.php'),
            'ext/mbstring Module::jitInit must register foldMbNumericEntityHook'
        );
        $this->assertStringContainsString(
            'initXsltHook',
            (string) file_get_contents(dirname(__DIR__, 2).'/ext/xsl/Module.php'),
            'ext/xsl Module::jitInit must register initXsltHook'
        );
    }

    public function testPosixBuiltinsDoNotImportPosixKernels(): void
    {
        $root = dirname(__DIR__, 2);
        $module = (string) file_get_contents($root.'/ext/posix/Module.php');
        $this->assertStringContainsString(
            'posixNested = new JitPosixNestedKernelsFacade()',
            $module,
            'ext/posix Module::jitInit must register JitPosixNestedKernelsFacade'
        );
        $this->assertStringContainsString(
            'requirePosixNested',
            (string) file_get_contents($root.'/lib/JIT/ExtensionLoweringHooks.php'),
            'ExtensionLoweringHooks must expose requirePosixNested()'
        );
        foreach (glob($root.'/lib/JIT/Builtin/Posix*Jit.php') ?: [] as $path) {
            $src = (string) file_get_contents($path);
            $stripped = (string) preg_replace('~/\*.*?\*/~s', '', $src);
            $stripped = (string) preg_replace('~//.*$~m', '', $stripped);
            $this->assertDoesNotMatchRegularExpression(
                '/PHPCompiler\\\\ext\\\\posix\\\\/',
                $stripped,
                basename($path).' still imports ext\\posix — use PosixNestedJitKernels'
            );
            // posix_getpid NestedJIT leaf lives in ext/standard (shared with getmypid).
            if ('PosixGetpidJit.php' === basename($path)) {
                continue;
            }
            $this->assertStringContainsString(
                'requirePosixNested()',
                $src,
                basename($path).' must dispatch NestedJIT via requirePosixNested()'
            );
        }
    }

    public function testFilterBuiltinsDoNotImportFilterExtension(): void
    {
        $root = dirname(__DIR__, 2);
        $module = (string) file_get_contents($root.'/ext/filter/Module.php');
        $this->assertStringContainsString(
            'filter = new JitFilterExtensionHooksFacade()',
            $module,
            'ext/filter Module::jitInit must register JitFilterExtensionHooksFacade'
        );
        $this->assertStringContainsString(
            'requireFilter',
            (string) file_get_contents($root.'/lib/JIT/ExtensionLoweringHooks.php'),
            'ExtensionLoweringHooks must expose requireFilter()'
        );
        $files = [
            'lib/JIT/Builtin/FilterVarArrayLlvm.php',
            'lib/JIT/Builtin/FilterVarArrayRuntime.php',
            'lib/JIT/Builtin/FilterVarRequireArrayLlvm.php',
            'lib/JIT/Builtin/FilterInputTypeJit.php',
            'lib/JIT/JitFilterInputTypeArg.php',
        ];
        foreach ($files as $rel) {
            $src = (string) file_get_contents($root.'/'.$rel);
            $stripped = (string) preg_replace('~/\*.*?\*/~s', '', $src);
            $stripped = (string) preg_replace('~//.*$~m', '', $stripped);
            $this->assertDoesNotMatchRegularExpression(
                '/PHPCompiler\\\\ext\\\\filter\\\\/',
                $stripped,
                $rel.' still imports ext\\filter — use FilterExtensionHooks'
            );
        }
    }

    public function testCalendarBuiltinsDoNotImportCalendarExtension(): void
    {
        $root = dirname(__DIR__, 2);
        $module = (string) file_get_contents($root.'/ext/calendar/Module.php');
        $this->assertStringContainsString(
            'calendar = new JitCalendarExtensionHooksFacade()',
            $module,
            'ext/calendar Module::jitInit must register JitCalendarExtensionHooksFacade'
        );
        $this->assertStringContainsString(
            'requireCalendar',
            (string) file_get_contents($root.'/lib/JIT/ExtensionLoweringHooks.php'),
            'ExtensionLoweringHooks must expose requireCalendar()'
        );
        $files = [
            'lib/JIT/Builtin/CalInfoRuntime.php',
            'lib/JIT/Builtin/CalFromJdRuntime.php',
        ];
        foreach ($files as $rel) {
            $src = (string) file_get_contents($root.'/'.$rel);
            $stripped = (string) preg_replace('~/\*.*?\*/~s', '', $src);
            $stripped = (string) preg_replace('~//.*$~m', '', $stripped);
            // NestedJIT helper FQCN strings use doubled backslashes; ban single-\ use/imports.
            $this->assertDoesNotMatchRegularExpression(
                '/PHPCompiler\\\\ext\\\\calendar\\\\/',
                $stripped,
                $rel.' still imports ext\\calendar — use CalendarExtensionHooks'
            );
            $this->assertStringContainsString(
                'requireCalendar()',
                $src,
                $rel.' must dispatch compile-time embeds via requireCalendar()'
            );
        }
    }

    public function testRandomCallProxiesDoNotImportRandomExtension(): void
    {
        $root = dirname(__DIR__, 2);
        $module = (string) file_get_contents($root.'/ext/random/Module.php');
        $this->assertStringContainsString(
            'random = new JitRandomExtensionHooksFacade()',
            $module,
            'ext/random Module::jitInit must register JitRandomExtensionHooksFacade'
        );
        $this->assertStringContainsString(
            'requireRandom',
            (string) file_get_contents($root.'/lib/JIT/ExtensionLoweringHooks.php'),
            'ExtensionLoweringHooks must expose requireRandom()'
        );
        $files = [
            'lib/JIT/Call/RandomizerConstruct.php',
            'lib/JIT/Call/RandomizerGetBytesFromString.php',
            'lib/JIT/Call/RandomizerMt19937Construct.php',
        ];
        foreach ($files as $rel) {
            $src = (string) file_get_contents($root.'/'.$rel);
            $stripped = (string) preg_replace('~/\*.*?\*/~s', '', $src);
            $stripped = (string) preg_replace('~//.*$~m', '', $stripped);
            $this->assertDoesNotMatchRegularExpression(
                '/PHPCompiler\\\\ext\\\\random\\\\/',
                $stripped,
                $rel.' still imports ext\\random — use RandomExtensionHooks'
            );
            $this->assertStringContainsString(
                'requireRandom()',
                $src,
                $rel.' must dispatch via requireRandom()'
            );
        }
    }

    public function testOpensslBuiltinsDoNotImportOpensslExtension(): void
    {
        $root = dirname(__DIR__, 2);
        $module = (string) file_get_contents($root.'/ext/openssl/Module.php');
        $this->assertStringContainsString(
            'openssl = new JitOpensslExtensionHooksFacade()',
            $module,
            'ext/openssl Module::jitInit must register JitOpensslExtensionHooksFacade'
        );
        $this->assertStringContainsString(
            'requireOpenssl',
            (string) file_get_contents($root.'/lib/JIT/ExtensionLoweringHooks.php'),
            'ExtensionLoweringHooks must expose requireOpenssl()'
        );
        $this->assertStringContainsString(
            'OpensslHostProbe',
            (string) file_get_contents($root.'/lib/AOT/Linker.php'),
            'Linker must probe openssl via OpensslHostProbe, not ext\\openssl'
        );
        $files = [
            'lib/JIT/Builtin/OpensslEncryptRuntime.php',
            'lib/JIT/Builtin/OpensslSignRuntime.php',
            'lib/AOT/Linker.php',
        ];
        foreach ($files as $rel) {
            $src = (string) file_get_contents($root.'/'.$rel);
            $stripped = (string) preg_replace('~/\*.*?\*/~s', '', $src);
            $stripped = (string) preg_replace('~//.*$~m', '', $stripped);
            // NestedJIT helper FQCN strings use doubled backslashes; ban single-\ use/imports.
            $this->assertDoesNotMatchRegularExpression(
                '/PHPCompiler\\\\ext\\\\openssl\\\\/',
                $stripped,
                $rel.' still imports ext\\openssl — use OpensslExtensionHooks / OpensslHostProbe'
            );
        }
        foreach (['lib/JIT/Builtin/OpensslEncryptRuntime.php', 'lib/JIT/Builtin/OpensslSignRuntime.php'] as $rel) {
            $src = (string) file_get_contents($root.'/'.$rel);
            $this->assertStringContainsString(
                'extensionLowering->openssl',
                $src,
                $rel.' must dispatch EVP leaves via extensionLowering->openssl'
            );
        }
    }

    /**
     * @dataProvider coreJitHelperFilesWithoutNonStandardExtImports
     */
    public function testCoreJitHelpersDoNotImportNonStandardExt(string $relPath): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2).'/'.$relPath);
        $stripped = (string) preg_replace('~/\*.*?\*/~s', '', $src);
        $stripped = (string) preg_replace('~//.*$~m', '', $stripped);
        $this->assertDoesNotMatchRegularExpression(
            '/PHPCompiler\\\\ext\\\\(?!standard\\\\)/',
            $stripped,
            $relPath.' still imports a non-standard ext — register via ExtensionLoweringHooks'
        );
    }

    /** @return list<array{string}> */
    public static function coreJitHelperFilesWithoutNonStandardExtImports(): array
    {
        $files = [
            ['lib/JIT/IssetHelperLlvm.php'],
            ['lib/JIT/EmptyDimensionLlvm.php'],
            ['lib/JIT/UnsetHelperLlvm.php'],
            ['lib/JIT/ValueEchoHelper.php'],
            ['lib/JIT/JitNativeString.php'],
            ['lib/JIT/SimpleXmlForeachSnapshot.php'],
            ['lib/JIT/XsltInstanceMethodJit.php'],
            ['lib/JIT/XmlWriterInstanceMethodJit.php'],
            ['lib/JIT/XmlReaderInstanceMethodJit.php'],
        ];
        foreach (glob(dirname(__DIR__, 2).'/lib/JIT/Builtin/Posix*Jit.php') ?: [] as $path) {
            $files[] = ['lib/JIT/Builtin/'.basename($path)];
        }

        return $files;
    }
}
