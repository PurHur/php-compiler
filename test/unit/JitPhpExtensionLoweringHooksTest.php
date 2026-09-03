<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Guard: lib/JIT.php must not import SimpleXML/XMLReader/XMLWriter/DOM textContent
 * user-script helpers after Module::jitInit registration (#36204).
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
}
