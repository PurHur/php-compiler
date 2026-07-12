<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xsl;

use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;

/**
 * XSLTProcessor — host ext/xsl bridge v1 (php-src ext/xsl/xsltprocessor.c; #3665).
 */
final class VmXsl
{
    public const CLASS_LC = 'xsltprocessor';

    public static function registerClass(Context $ctx): void
    {
        if (isset($ctx->classes[self::CLASS_LC])) {
            return;
        }

        if (!XslExtensionPolicy::advertisesExtension()) {
            return;
        }

        $pub = \PHPCfg\Func::FLAG_PUBLIC;

        $entry = new ClassEntry('XSLTProcessor');
        $construct = new XsltProcessorConstruct();
        $entry->constructor = $construct;
        $entry->methods['__construct'] = $construct;
        $entry->methodVisibility['__construct'] = $pub;
        $entry->methods['importstylesheet'] = new XsltProcessorImportStylesheet();
        $entry->methodVisibility['importstylesheet'] = $pub;
        $entry->methodNames['importstylesheet'] = 'importStylesheet';
        $entry->methods['transformtoxml'] = new XsltProcessorTransformToXml();
        $entry->methodVisibility['transformtoxml'] = $pub;
        $entry->methodNames['transformtoxml'] = 'transformToXML';
        $entry->methods['transformtodoc'] = new XsltProcessorTransformToDoc();
        $entry->methodVisibility['transformtodoc'] = $pub;
        $entry->methodNames['transformtodoc'] = 'transformToDoc';

        $ctx->classes[self::CLASS_LC] = $entry;
        $ctx->classes[self::CLASS_LC]->isInternal = true;
    }

    /**
     * Withhold XSLTProcessor from class_exists() when host ext/xsl is absent (#3665).
     */
    public static function isHiddenClassEntry(ClassEntry $entry): bool
    {
        if (XslExtensionPolicy::advertisesExtension()) {
            return false;
        }

        return self::CLASS_LC === strtolower(ltrim($entry->name, '\\'));
    }

    public static function requireProcessor(ObjectEntry $entry, string $label): ObjectEntry
    {
        if (self::CLASS_LC !== strtolower($entry->class->name)) {
            throw new \TypeError(sprintf('%s(): Argument must be XSLTProcessor, %s given', $label, $entry->class->name));
        }
        if (!XsltRegistry::has($entry)) {
            throw new \LogicException($label.' called on uninitialized XSLTProcessor');
        }

        return $entry;
    }

    public static function construct(ObjectEntry $entry): void
    {
        XsltRegistry::attach($entry, XsltHostBridge::createProcessor());
    }

    public static function importStylesheet(ObjectEntry $entry, ObjectEntry $stylesheet): void
    {
        $hostStylesheet = VmXslDomBridge::vmDocumentToHost($stylesheet);
        XsltHostBridge::importStylesheet(XsltRegistry::processor($entry), $hostStylesheet);
    }

    /** @return string|false */
    public static function transformToXml(ObjectEntry $entry, ObjectEntry $document)
    {
        $hostDoc = VmXslDomBridge::vmDocumentToHost($document);

        return XsltHostBridge::transformToXml(XsltRegistry::processor($entry), $hostDoc);
    }

    /** @return ObjectEntry|false */
    public static function transformToDoc(Context $ctx, ObjectEntry $entry, ObjectEntry $document)
    {
        $hostDoc = VmXslDomBridge::vmDocumentToHost($document);
        $hostResult = XsltHostBridge::transformToDoc(XsltRegistry::processor($entry), $hostDoc);
        if (false === $hostResult) {
            return false;
        }

        return VmXslDomBridge::hostDocumentToVm($ctx, $hostResult);
    }
}
