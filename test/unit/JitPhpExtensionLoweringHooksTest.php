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

    public function testZipCallProxiesDoNotImportZipExtension(): void
    {
        $root = dirname(__DIR__, 2);
        $module = (string) file_get_contents($root.'/ext/zip/Module.php');
        $this->assertStringContainsString(
            'zip = new JitZipExtensionHooksFacade()',
            $module,
            'ext/zip Module::jitInit must register JitZipExtensionHooksFacade'
        );
        $this->assertStringContainsString(
            'requireZip',
            (string) file_get_contents($root.'/lib/JIT/ExtensionLoweringHooks.php'),
            'ExtensionLoweringHooks must expose requireZip()'
        );
        $files = [
            'lib/JIT/Call/ZipArchiveConstruct.php',
            'lib/JIT/Call/ZipArchiveMethod.php',
        ];
        foreach ($files as $rel) {
            $src = (string) file_get_contents($root.'/'.$rel);
            $stripped = (string) preg_replace('~/\*.*?\*/~s', '', $src);
            $stripped = (string) preg_replace('~//.*$~m', '', $stripped);
            $this->assertDoesNotMatchRegularExpression(
                '/PHPCompiler\\\\ext\\\\zip\\\\/',
                $stripped,
                $rel.' still imports ext\\zip — use ZipExtensionHooks'
            );
            $this->assertStringContainsString(
                'requireZip()',
                $src,
                $rel.' must dispatch via requireZip()'
            );
        }
    }

    public function testBcMathCallProxiesDoNotImportBcmathExtension(): void
    {
        $root = dirname(__DIR__, 2);
        $module = (string) file_get_contents($root.'/ext/bcmath/Module.php');
        $this->assertStringContainsString(
            'bcmath = new JitBcMathExtensionHooksFacade()',
            $module,
            'ext/bcmath Module::jitInit must register JitBcMathExtensionHooksFacade'
        );
        $this->assertStringContainsString(
            'requireBcMath',
            (string) file_get_contents($root.'/lib/JIT/ExtensionLoweringHooks.php'),
            'ExtensionLoweringHooks must expose requireBcMath()'
        );
        $files = [
            'lib/JIT/Call/BcMathNumberConstruct.php',
            'lib/JIT/Call/BcMathNumberMethod.php',
            'lib/JIT/Call/BcMathNumberToString.php',
        ];
        foreach ($files as $rel) {
            $src = (string) file_get_contents($root.'/'.$rel);
            $stripped = (string) preg_replace('~/\*.*?\*/~s', '', $src);
            $stripped = (string) preg_replace('~//.*$~m', '', $stripped);
            $this->assertDoesNotMatchRegularExpression(
                '/PHPCompiler\\\\ext\\\\bcmath\\\\/',
                $stripped,
                $rel.' still imports ext\\bcmath — use BcMathExtensionHooks'
            );
            $this->assertStringContainsString(
                'requireBcMath()',
                $src,
                $rel.' must dispatch via requireBcMath()'
            );
        }
    }

    public function testIntlCallProxiesDoNotImportIntlExtension(): void
    {
        $root = dirname(__DIR__, 2);
        $module = (string) file_get_contents($root.'/ext/intl/Module.php');
        $this->assertStringContainsString(
            'intl = new JitIntlExtensionHooksFacade()',
            $module,
            'ext/intl Module::jitInit must register JitIntlExtensionHooksFacade'
        );
        $this->assertStringContainsString(
            'requireIntl',
            (string) file_get_contents($root.'/lib/JIT/ExtensionLoweringHooks.php'),
            'ExtensionLoweringHooks must expose requireIntl()'
        );
        $files = [
            'lib/JIT/Call/CollatorCompare.php',
            'lib/JIT/Call/MessageFormatterConstruct.php',
            'lib/JIT/Call/MessageFormatterFormat.php',
            'lib/JIT/Call/NormalizerNormalize.php',
            'lib/JIT/Call/NumberFormatterCreate.php',
            'lib/JIT/Call/NumberFormatterFormat.php',
            'lib/JIT/Call/IntlDateFormatterCreate.php',
            'lib/JIT/Call/IntlDateFormatterFormat.php',
            'lib/JIT/Call/TransliteratorCreate.php',
            'lib/JIT/Call/TransliteratorTransliterate.php',
        ];
        foreach ($files as $rel) {
            $src = (string) file_get_contents($root.'/'.$rel);
            $stripped = (string) preg_replace('~/\*.*?\*/~s', '', $src);
            $stripped = (string) preg_replace('~//.*$~m', '', $stripped);
            $this->assertDoesNotMatchRegularExpression(
                '/PHPCompiler\\\\ext\\\\intl\\\\/',
                $stripped,
                $rel.' still imports ext\\intl — use IntlExtensionHooks'
            );
            $this->assertStringContainsString(
                'requireIntl()',
                $src,
                $rel.' must dispatch via requireIntl()'
            );
        }
    }

    public function testSimpleXmlCallProxiesDoNotImportSimplexmlExtension(): void
    {
        $root = dirname(__DIR__, 2);
        $module = (string) file_get_contents($root.'/ext/simplexml/Module.php');
        $this->assertStringContainsString(
            'simplexml = new JitSimpleXmlExtensionHooksFacade()',
            $module,
            'ext/simplexml Module::jitInit must register JitSimpleXmlExtensionHooksFacade'
        );
        $this->assertStringContainsString(
            'requireSimpleXml',
            (string) file_get_contents($root.'/lib/JIT/ExtensionLoweringHooks.php'),
            'ExtensionLoweringHooks must expose requireSimpleXml()'
        );
        $files = [
            'lib/JIT/Call/SimpleXMLElementAddChild.php',
            'lib/JIT/Call/SimpleXMLElementAsXml.php',
            'lib/JIT/Call/SimpleXMLElementAttributes.php',
            'lib/JIT/Call/SimpleXMLElementChildren.php',
            'lib/JIT/Call/SimpleXMLElementConstruct.php',
            'lib/JIT/Call/SimpleXMLElementCount.php',
            'lib/JIT/Call/SimpleXMLElementGet.php',
            'lib/JIT/Call/SimpleXMLElementGetDocNamespaces.php',
            'lib/JIT/Call/SimpleXMLElementGetName.php',
            'lib/JIT/Call/SimpleXMLElementGetNamespaces.php',
            'lib/JIT/Call/SimpleXMLElementOffsetGet.php',
            'lib/JIT/Call/SimpleXMLElementRegisterXPathNamespace.php',
            'lib/JIT/Call/SimpleXMLElementToString.php',
            'lib/JIT/Call/SimpleXMLElementXpath.php',
        ];
        foreach ($files as $rel) {
            $src = (string) file_get_contents($root.'/'.$rel);
            $stripped = (string) preg_replace('~/\*.*?\*/~s', '', $src);
            $stripped = (string) preg_replace('~//.*$~m', '', $stripped);
            $this->assertDoesNotMatchRegularExpression(
                '/PHPCompiler\\\\ext\\\\simplexml\\\\/',
                $stripped,
                $rel.' still imports ext\\simplexml — use SimpleXmlExtensionHooks'
            );
            $this->assertStringContainsString(
                'requireSimpleXml()',
                $src,
                $rel.' must dispatch via requireSimpleXml()'
            );
        }
    }

    public function testXmlReaderCallProxiesDoNotImportXmlreaderExtension(): void
    {
        $root = dirname(__DIR__, 2);
        $module = (string) file_get_contents($root.'/ext/xmlreader/Module.php');
        $this->assertStringContainsString(
            'xmlreader = new JitXmlReaderExtensionHooksFacade()',
            $module,
            'ext/xmlreader Module::jitInit must register JitXmlReaderExtensionHooksFacade'
        );
        $this->assertStringContainsString(
            'requireXmlReader',
            (string) file_get_contents($root.'/lib/JIT/ExtensionLoweringHooks.php'),
            'ExtensionLoweringHooks must expose requireXmlReader()'
        );
        $files = [
            'lib/JIT/Call/XmlReaderFromStream.php',
            'lib/JIT/Call/XmlReaderFromString.php',
            'lib/JIT/Call/XmlReaderFromUri.php',
            'lib/JIT/Call/XmlReaderMethod.php',
            'lib/JIT/Call/XmlReaderOpen.php',
            'lib/JIT/Call/XmlReaderXML.php',
        ];
        foreach ($files as $rel) {
            $src = (string) file_get_contents($root.'/'.$rel);
            $stripped = (string) preg_replace('~/\*.*?\*/~s', '', $src);
            $stripped = (string) preg_replace('~//.*$~m', '', $stripped);
            $this->assertDoesNotMatchRegularExpression(
                '/PHPCompiler\\\\ext\\\\xmlreader\\\\/',
                $stripped,
                $rel.' still imports ext\\xmlreader — use XmlReaderExtensionHooks'
            );
            $this->assertStringContainsString(
                'requireXmlReader()',
                $src,
                $rel.' must dispatch via requireXmlReader()'
            );
        }
    }

    public function testXmlWriterCallProxiesDoNotImportXmlwriterExtension(): void
    {
        $root = dirname(__DIR__, 2);
        $module = (string) file_get_contents($root.'/ext/xmlwriter/Module.php');
        $this->assertStringContainsString(
            'xmlwriter = new JitXmlWriterExtensionHooksFacade()',
            $module,
            'ext/xmlwriter Module::jitInit must register JitXmlWriterExtensionHooksFacade'
        );
        $this->assertStringContainsString(
            'requireXmlWriter',
            (string) file_get_contents($root.'/lib/JIT/ExtensionLoweringHooks.php'),
            'ExtensionLoweringHooks must expose requireXmlWriter()'
        );
        $files = [
            'lib/JIT/Call/XmlWriterMethod.php',
            'lib/JIT/Call/XmlWriterToMemory.php',
            'lib/JIT/Call/XmlWriterToStream.php',
            'lib/JIT/Call/XmlWriterToUri.php',
        ];
        foreach ($files as $rel) {
            $src = (string) file_get_contents($root.'/'.$rel);
            $stripped = (string) preg_replace('~/\*.*?\*/~s', '', $src);
            $stripped = (string) preg_replace('~//.*$~m', '', $stripped);
            $this->assertDoesNotMatchRegularExpression(
                '/PHPCompiler\\\\ext\\\\xmlwriter\\\\/',
                $stripped,
                $rel.' still imports ext\\xmlwriter — use XmlWriterExtensionHooks'
            );
            $this->assertStringContainsString(
                'requireXmlWriter()',
                $src,
                $rel.' must dispatch via requireXmlWriter()'
            );
        }
    }

    public function testFileinfoCallProxiesDoNotImportFileinfoExtension(): void
    {
        $root = dirname(__DIR__, 2);
        $module = (string) file_get_contents($root.'/ext/fileinfo/Module.php');
        $this->assertStringContainsString(
            'fileinfo = new JitFileinfoExtensionHooksFacade()',
            $module,
            'ext/fileinfo Module::jitInit must register JitFileinfoExtensionHooksFacade'
        );
        $this->assertStringContainsString(
            'requireFileinfo',
            (string) file_get_contents($root.'/lib/JIT/ExtensionLoweringHooks.php'),
            'ExtensionLoweringHooks must expose requireFileinfo()'
        );
        $files = [
            'lib/JIT/Call/FinfoSetFlags.php',
            'lib/JIT/Call/FinfoBuffer.php',
            'lib/JIT/Call/FinfoFile.php',
        ];
        foreach ($files as $rel) {
            $src = (string) file_get_contents($root.'/'.$rel);
            $stripped = (string) preg_replace('~/\*.*?\*/~s', '', $src);
            $stripped = (string) preg_replace('~//.*$~m', '', $stripped);
            $this->assertDoesNotMatchRegularExpression(
                '/PHPCompiler\\\\ext\\\\fileinfo\\\\/',
                $stripped,
                $rel.' still imports ext\\fileinfo — use FileinfoExtensionHooks'
            );
            $this->assertStringContainsString(
                'requireFileinfo()',
                $src,
                $rel.' must dispatch via requireFileinfo()'
            );
        }
    }

    public function testXslCallProxiesDoNotImportXslExtension(): void
    {
        $root = dirname(__DIR__, 2);
        $module = (string) file_get_contents($root.'/ext/xsl/Module.php');
        $this->assertStringContainsString(
            'xsl = new JitXslExtensionHooksFacade()',
            $module,
            'ext/xsl Module::jitInit must register JitXslExtensionHooksFacade'
        );
        $this->assertStringContainsString(
            'requireXsl',
            (string) file_get_contents($root.'/lib/JIT/ExtensionLoweringHooks.php'),
            'ExtensionLoweringHooks must expose requireXsl()'
        );
        $src = (string) file_get_contents($root.'/lib/JIT/Call/XsltMethod.php');
        $stripped = (string) preg_replace('~/\*.*?\*/~s', '', $src);
        $stripped = (string) preg_replace('~//.*$~m', '', $stripped);
        $this->assertDoesNotMatchRegularExpression(
            '/PHPCompiler\\\\ext\\\\xsl\\\\/',
            $stripped,
            'lib/JIT/Call/XsltMethod.php still imports ext\\xsl — use XslExtensionHooks'
        );
        $this->assertStringContainsString(
            'requireXsl()',
            $src,
            'lib/JIT/Call/XsltMethod.php must dispatch via requireXsl()'
        );
    }

    public function testSqlite3CallProxiesDoNotImportSqlite3Extension(): void
    {
        $root = dirname(__DIR__, 2);
        $module = (string) file_get_contents($root.'/ext/sqlite3/Module.php');
        $this->assertStringContainsString(
            'sqlite3 = new JitSqlite3ExtensionHooksFacade()',
            $module,
            'ext/sqlite3 Module::jitInit must register JitSqlite3ExtensionHooksFacade'
        );
        $this->assertStringContainsString(
            'requireSqlite3',
            (string) file_get_contents($root.'/lib/JIT/ExtensionLoweringHooks.php'),
            'ExtensionLoweringHooks must expose requireSqlite3()'
        );
        $files = [
            'lib/JIT/Call/Sqlite3Method.php',
            'lib/JIT/Call/Sqlite3ResultMethod.php',
            'lib/JIT/Call/Sqlite3StmtMethod.php',
        ];
        foreach ($files as $rel) {
            $src = (string) file_get_contents($root.'/'.$rel);
            $stripped = (string) preg_replace('~/\*.*?\*/~s', '', $src);
            $stripped = (string) preg_replace('~//.*$~m', '', $stripped);
            $this->assertDoesNotMatchRegularExpression(
                '/PHPCompiler\\\\ext\\\\sqlite3\\\\/',
                $stripped,
                $rel.' still imports ext\\sqlite3 — use Sqlite3ExtensionHooks'
            );
            $this->assertStringContainsString(
                'requireSqlite3()',
                $src,
                $rel.' must dispatch via requireSqlite3()'
            );
        }
    }

    public function testTokenizerCallProxiesDoNotImportTokenizerExtension(): void
    {
        $root = dirname(__DIR__, 2);
        $module = (string) file_get_contents($root.'/ext/tokenizer/Module.php');
        $this->assertStringContainsString(
            'tokenizer = new JitTokenizerExtensionHooksFacade()',
            $module,
            'ext/tokenizer Module::jitInit must register JitTokenizerExtensionHooksFacade'
        );
        $this->assertStringContainsString(
            'requireTokenizer',
            (string) file_get_contents($root.'/lib/JIT/ExtensionLoweringHooks.php'),
            'ExtensionLoweringHooks must expose requireTokenizer()'
        );
        $files = [
            'lib/JIT/Call/PhpTokenTokenize.php',
            'lib/JIT/Call/PhpTokenConstruct.php',
            'lib/JIT/Call/PhpTokenGetTokenName.php',
        ];
        foreach ($files as $rel) {
            $src = (string) file_get_contents($root.'/'.$rel);
            $stripped = (string) preg_replace('~/\*.*?\*/~s', '', $src);
            $stripped = (string) preg_replace('~//.*$~m', '', $stripped);
            $this->assertDoesNotMatchRegularExpression(
                '/PHPCompiler\\\\ext\\\\tokenizer\\\\/',
                $stripped,
                $rel.' still imports ext\\tokenizer — use TokenizerExtensionHooks'
            );
            $this->assertStringContainsString(
                'requireTokenizer()',
                $src,
                $rel.' must dispatch via requireTokenizer()'
            );
        }
    }

    public function testPdoCallProxiesDoNotImportPdoExtension(): void
    {
        $root = dirname(__DIR__, 2);
        $module = (string) file_get_contents($root.'/ext/pdo/Module.php');
        $this->assertStringContainsString(
            'pdo = new JitPdoExtensionHooksFacade()',
            $module,
            'ext/pdo Module::jitInit must register JitPdoExtensionHooksFacade'
        );
        $this->assertStringContainsString(
            'requirePdo',
            (string) file_get_contents($root.'/lib/JIT/ExtensionLoweringHooks.php'),
            'ExtensionLoweringHooks must expose requirePdo()'
        );
        $files = [
            'lib/JIT/Call/PdoConstruct.php',
            'lib/JIT/Call/PdoGetAvailableDrivers.php',
        ];
        foreach ($files as $rel) {
            $src = (string) file_get_contents($root.'/'.$rel);
            $stripped = (string) preg_replace('~/\*.*?\*/~s', '', $src);
            $stripped = (string) preg_replace('~//.*$~m', '', $stripped);
            $this->assertDoesNotMatchRegularExpression(
                '/PHPCompiler\\\\ext\\\\pdo\\\\/',
                $stripped,
                $rel.' still imports ext\\pdo — use PdoExtensionHooks'
            );
            $this->assertStringContainsString(
                'requirePdo()',
                $src,
                $rel.' must dispatch via requirePdo()'
            );
        }
    }

    public function testDomThinCallProxiesDoNotImportDomExtension(): void
    {
        $root = dirname(__DIR__, 2);
        $module = (string) file_get_contents($root.'/ext/dom/Module.php');
        $this->assertStringContainsString(
            'dom = new JitDomExtensionHooksFacade()',
            $module,
            'ext/dom Module::jitInit must register JitDomExtensionHooksFacade'
        );
        $this->assertStringContainsString(
            'requireDom',
            (string) file_get_contents($root.'/lib/JIT/ExtensionLoweringHooks.php'),
            'ExtensionLoweringHooks must expose requireDom()'
        );
        $files = [
            'lib/JIT/Call/DomDocumentCreateElement.php',
            'lib/JIT/Call/DomCharacterDataAppendData.php',
            'lib/JIT/Call/DomNamedNodeMapItem.php',
            'lib/JIT/Call/DomNodeCloneNode.php',
            'lib/JIT/Call/DomTextSplitText.php',
            'lib/JIT/Call/DomXPathQuery.php',
            'lib/JIT/Call/DomDocumentLoadXML.php',
            'lib/JIT/Call/DomDocumentSaveHTML.php',
            'lib/JIT/Call/DomHtmlDocumentCreateFromString.php',
            'lib/JIT/Call/DomImplementationCreateDocument.php',
        ];
        foreach ($files as $rel) {
            $src = (string) file_get_contents($root.'/'.$rel);
            $stripped = (string) preg_replace('~/\*.*?\*/~s', '', $src);
            $stripped = (string) preg_replace('~//.*$~m', '', $stripped);
            $this->assertDoesNotMatchRegularExpression(
                '/PHPCompiler\\\\ext\\\\dom\\\\/',
                $stripped,
                $rel.' still imports ext\\dom — use DomExtensionHooks'
            );
            $this->assertStringContainsString(
                'requireDom()',
                $src,
                $rel.' must dispatch via requireDom()'
            );
        }
        $thinCount = 0;
        foreach (glob($root.'/lib/JIT/Call/Dom*.php') ?: [] as $path) {
            $src = (string) file_get_contents($path);
            if (!str_contains($src, 'requireDom()')) {
                continue;
            }
            ++$thinCount;
            $stripped = (string) preg_replace('~/\*.*?\*/~s', '', $src);
            $stripped = (string) preg_replace('~//.*$~m', '', $stripped);
            $this->assertDoesNotMatchRegularExpression(
                '/PHPCompiler\\\\ext\\\\dom\\\\/',
                $stripped,
                basename($path).' still imports ext\\dom — use DomExtensionHooks'
            );
        }
        $this->assertSame(
            90,
            $thinCount,
            'expected 90 thin Dom Call proxies routed via requireDom()'
        );
    }

    public function testDomRuntimeKernelsDoNotImportDomExtension(): void
    {
        $root = dirname(__DIR__, 2);
        $this->assertStringContainsString(
            'ensureDocumentMethodBridge',
            (string) file_get_contents($root.'/lib/JIT/DomExtensionHooks.php'),
            'DomExtensionHooks must expose ensureDocumentMethodBridge()'
        );
        $this->assertStringContainsString(
            'function ensureDocumentMethodBridge',
            (string) file_get_contents($root.'/ext/dom/JitDomExtensionHooksFacade.php'),
            'JitDomExtensionHooksFacade must implement ensureDocumentMethodBridge()'
        );
        $files = [
            'lib/JIT/Builtin/DomSaveXMLRuntime.php',
            'lib/JIT/Builtin/DomLoadRuntime.php',
            'lib/JIT/Builtin/DomNormalizeRuntime.php',
            'lib/JIT/Builtin/DomDocumentValidateRuntime.php',
            'lib/JIT/Builtin/DomNodeChildPropertyRuntime.php',
            'lib/JIT/Builtin/DomXPathEvaluateRuntime.php',
            'lib/JIT/Builtin/DomSetIdAttributeRuntime.php',
            'lib/JIT/Builtin/DomImportNodeRuntime.php',
        ];
        $hooked = 0;
        foreach (glob($root.'/lib/JIT/Builtin/Dom*Runtime.php') ?: [] as $path) {
            $src = (string) file_get_contents($path);
            if (!str_contains($src, 'ensureDocumentMethodBridge')) {
                continue;
            }
            ++$hooked;
            $stripped = (string) preg_replace('~/\*.*?\*/~s', '', $src);
            $stripped = (string) preg_replace('~//.*$~m', '', $stripped);
            $this->assertDoesNotMatchRegularExpression(
                '/use PHPCompiler\\\\ext\\\\dom\\\\/',
                $stripped,
                basename($path).' still imports ext\\dom — use DomExtensionHooks'
            );
            $this->assertStringNotContainsString(
                'JitDomDocumentMethodKernel::',
                $stripped,
                basename($path).' still calls JitDomDocumentMethodKernel directly'
            );
        }
        $this->assertSame(
            32,
            $hooked,
            'expected 32 Dom*Runtime files routed via ensureDocumentMethodBridge()'
        );
        foreach ($files as $rel) {
            $src = (string) file_get_contents($root.'/'.$rel);
            $this->assertStringContainsString(
                'ensureDocumentMethodBridge',
                $src,
                $rel.' must dispatch via ensureDocumentMethodBridge()'
            );
        }
    }

    public function testSplHeapCallProxyDoesNotImportSplExtension(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2).'/lib/JIT/Call/SplHeapMethod.php');
        $stripped = (string) preg_replace('~/\*.*?\*/~s', '', $src);
        $stripped = (string) preg_replace('~//.*$~m', '', $stripped);
        $this->assertDoesNotMatchRegularExpression(
            '/PHPCompiler\\\\ext\\\\spl\\\\/',
            $stripped,
            'lib/JIT/Call/SplHeapMethod.php still imports ext\\spl — pass KIND from Module::jitInit'
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
