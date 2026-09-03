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
        return [
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
    }
}
