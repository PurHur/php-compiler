<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** sg_vm_context global + DOM user-script defer path (#17391). */
final class DomActiveContextAotTest extends TestCase
{
    public function testRuntimeInitVmContextStoresActiveContextGlobal(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/RuntimeInitVmContext.php');
        $this->assertStringContainsString('VmActiveContextLlvm::storeContext', $source);
    }

    public function testDomInstanceMethodRuntimeWiresActiveContextProxy(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/DomInstanceMethodRuntime.php');
        $this->assertStringContainsString('ensureActiveContextProxy', $runtime);
        $this->assertStringContainsString('VmActiveContextInitLlvm::requestThinStandaloneInit', $runtime);
    }

    public function testVmDomJitFrameFallsBackToActiveContextHelper(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/dom/VmDomJitFrame.php');
        $this->assertStringContainsString('VmActiveContextJitHelper::resolve', $source);
    }

    public function testThinStandaloneInitSchedulesDomRegistration(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/VmActiveContextInitLlvm.php');
        $this->assertStringContainsString('DomStandaloneAotInitRuntime::ABI_NAME', $source);
        $this->assertStringContainsString('RuntimeInitVmContext::emit', $source);
        $this->assertStringContainsString('emitPendingBeforeSeal', $source);
        $this->assertStringNotContainsString('supportsDomTokenList', $source);
    }

    public function testDomInstanceMethodJitDefersGenericProxiesForUserScript(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/DomInstanceMethodJit.php');
        $this->assertStringContainsString('shouldDeferToVmClassMethodLowering', $source);
        $this->assertStringContainsString('DomDocumentCreateElement', $source);
        $this->assertStringContainsString('isUserScriptDirectMethod', $source);
        $this->assertStringContainsString('loadhtml', $source);
        $this->assertStringContainsString('loadhtmlfile', $source);
        $this->assertStringContainsString('loadxml', $source);
        $this->assertStringContainsString('savehtml', $source);
        $this->assertStringContainsString('savexml', $source);
        $this->assertStringContainsString('getelementbyid', $source);
        $this->assertStringContainsString('getelementsbytagname', $source);
        $this->assertStringContainsString('appendchild', $source);
        $this->assertStringContainsString('DomNodeAppendChild', $source);
        $this->assertStringContainsString('domnode::appendchild', $source);
        $this->assertStringContainsString('DomDocumentLoadHTML', $source);
        $this->assertStringContainsString('DomDocumentLoadHTMLFile', $source);
        $this->assertStringContainsString('DomDocumentGetElementById', $source);
        $this->assertStringContainsString('DomDocumentGetElementsByTagName', $source);
        $this->assertStringContainsString('DomDocumentLoadXML', $source);
        $this->assertStringContainsString('DomDocumentSaveHTML', $source);
        $this->assertStringContainsString('DomDocumentSaveXML', $source);
        $this->assertStringContainsString('isUserScriptGenericDomMethod', $source);
        $this->assertStringContainsString('domxpath::query', $source);
    }

    public function testDomLoadHTMLRuntimeSchedulesActiveContextInit(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/DomLoadHTMLRuntime.php');
        $this->assertStringContainsString('VmActiveContextInitLlvm::requestThinStandaloneInit', $source);
        $this->assertStringContainsString('ensureActiveContextProxy', $source);
    }

    public function testDomLoadHTMLRuntimeUsesObjectReceiverBridge(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/DomLoadHTMLRuntime.php');
        $this->assertStringContainsString('__object__*', $source);
        $this->assertStringContainsString('DomLoadHTMLJitHelper::loadHTMLArgv', $source);
        $helper = (string) file_get_contents(__DIR__.'/../../ext/dom/DomLoadHTMLJitHelper.php');
        $this->assertStringContainsString('Context $ctx', $helper);
        $this->assertStringContainsString('ObjectEntry $document', $helper);
        $cache = (string) file_get_contents(__DIR__.'/../../lib/AOT/HelperRuntimeCache.php');
        $this->assertStringNotContainsString('domloadhtmljithelper::loadhtmlargv', strtolower($cache));
    }

    public function testDomGetElementByIdUsesPureLlvmIdMap(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/dom/JitDomGetElementById.php');
        $this->assertStringContainsString('HashTableHelper::readHashtableFromValueBox', $source);
        $this->assertStringContainsString('VmDom::PROP_ELEMENT_ID_MAP', $source);
        $this->assertStringContainsString('TYPE_VALUE', $source);
        $call = (string) file_get_contents(__DIR__.'/../../lib/JIT/Call/DomDocumentGetElementById.php');
        $this->assertStringNotContainsString('DomGetElementByIdRuntime::ensureLinked', $call);
    }

    public function testDomRegistryUsesProcessGlobalBucket(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/dom/DomRegistry.php');
        $this->assertStringContainsString('__phpc_dom_registry', $source);
        $this->assertStringContainsString('$GLOBALS', $source);
    }

    public function testHelperRuntimeFingerprintHashesExtDomDeps(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/AOT/HelperRuntimeCache.php');
        $this->assertStringContainsString('unitDependencyFingerprintMaterial', $source);
        $this->assertStringContainsString('/ext/dom/VmDom.php', $source);
    }

    public function testLoadHTMLSyncsElementIdMapAfterParse(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/dom/VmDom.php');
        $pos = strpos($source, 'public static function loadHTML(');
        $this->assertNotFalse($pos);
        $body = substr($source, $pos, 8000);
        $this->assertStringContainsString('reindexDocumentIds', $body);
        $this->assertStringContainsString('syncElementIdMapProperty', $body);
        $this->assertStringContainsString('deferDocumentSlotSync', $body);
    }

    public function testLoadHTMLJitHelperSyncsDocumentSlots(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/dom/DomLoadHTMLJitHelper.php');
        $this->assertStringContainsString('Context $ctx', $source);
        $this->assertStringContainsString('ObjectEntry $document', $source);
        $this->assertStringContainsString('VmDom::loadHTML', $source);
        $this->assertStringNotContainsString('DomRegistry::entry', $source);
    }

    public function testDomElementTextContentUsesInitLinkedHelper(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/dom/JitDomElementTextContent.php');
        $this->assertStringContainsString('DomElementTextContentRuntime::ABI_NAME', $source);
        $this->assertStringContainsString('ObjectInstancePropertyLlvm', (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type/ObjectInstancePropertyLlvm.php'));
    }

    public function testDomLoadHTMLRuntimeDefinesAbi(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/DomLoadHTMLRuntime.php');
        $this->assertStringContainsString('DomLoadHTMLJitHelper::loadHTMLArgv', $source);
        $this->assertStringContainsString('__phpc_dom_load_html', $source);
    }

    public function testUserScriptStandaloneInitLinksDomExtensionOnly(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringContainsString('DomStandaloneAotInitRuntime::ensureLinked', $source);
        $this->assertStringNotContainsString('DomLoadHTMLRuntime::ensureLinked', $source);
    }

    public function testDomGetElementByIdResolvesScriptGlobalReceiverAfterLoadHTML(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT.php');
        $this->assertStringContainsString('resolveUserScriptDomDocumentReceiver', $source);
        $this->assertStringContainsString('getelementbyid', $source);
        $this->assertStringContainsString('domnode::appendchild', $source);
    }

    public function testDomDocumentLoadHTMLUserScriptClearsBuilderAfterInvoke(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Call/DomDocumentLoadHTML.php');
        $this->assertStringContainsString('main_cont_after_dom_lh', $source);
    }

    public function testDomLoadHTMLLoweringPassesObjectReceiver(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/dom/JitDomLoadHTML.php');
        $this->assertStringContainsString('loadObjectArg', $source);
        $this->assertStringContainsString('DomLoadHTMLRuntime::ABI_NAME', $source);
    }

    public function testDomGetElementByIdLoweringPassesValueBoxedId(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/dom/JitDomGetElementById.php');
        $this->assertStringContainsString('JitValueBox::valuePtrFromVariable', $source);
    }

    public function testDomDocumentCallHandlersEnsureRuntimeLinked(): void
    {
        $load = (string) file_get_contents(__DIR__.'/../../lib/JIT/Call/DomDocumentLoadHTML.php');
        $gei = (string) file_get_contents(__DIR__.'/../../lib/JIT/Call/DomDocumentGetElementById.php');
        $this->assertStringContainsString('DomLoadHTMLRuntime::ensureLinked', $load);
        $this->assertStringContainsString('JitDomGetElementById::invoke', $gei);
    }

    public function testDomDocumentMethodUserScriptBridgeUsesHelperLinkEntryChecks(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/DomDocumentMethodUserScriptLlvm.php');
        $this->assertStringContainsString('hasNamedBridgeEntry', $source);
        $this->assertStringContainsString('bridgeEntryForEmit', $source);
    }

    public function testDomInstanceMethodUserScriptRegistersProxiesBeforeHelperCompile(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/DomInstanceMethodUserScriptLlvm.php');
        $bridgePos = strpos($source, 'function ensureBridge');
        $this->assertNotFalse($bridgePos);
        $bridgeBody = substr($source, $bridgePos);
        $proxyPos = strpos($bridgeBody, 'ensureNestedHelperProxies');
        $compilePos = strpos($bridgeBody, 'ensureMainModuleHelperCompiled');
        $this->assertNotFalse($proxyPos);
        $this->assertNotFalse($compilePos);
        $this->assertLessThan($compilePos, $proxyPos, 'nested helper proxies must register before main-module compile');
    }

    public function testDomInstanceMethodJitSeedsDomElementPropertyLayout(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/DomInstanceMethodJit.php');
        $this->assertStringContainsString('ensureDomElementPropertyLayout', $source);
        $this->assertStringContainsString('nodeName', $source);
        $this->assertStringContainsString('tagName', $source);
        $this->assertStringContainsString('textContent', $source);
    }

    public function testDomSaveLoadUserScriptUsesPureLlvmLowering(): void
    {
        $saveHtml = (string) file_get_contents(__DIR__.'/../../ext/dom/JitDomSaveHTMLUserScript.php');
        $loadXml = (string) file_get_contents(__DIR__.'/../../ext/dom/JitDomLoadXMLUserScript.php');
        $saveXml = (string) file_get_contents(__DIR__.'/../../ext/dom/JitDomSaveXMLUserScript.php');
        $this->assertStringContainsString('lastCompileTimeParsedHtml', $saveHtml);
        $this->assertStringContainsString('lastCompileTimeXml', $loadXml);
        $this->assertStringContainsString('lastCompileTimeXml', $saveXml);
    }
}
