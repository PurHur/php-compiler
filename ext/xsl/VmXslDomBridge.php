<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xsl;

use PHPCompiler\ext\dom\VmDom;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/** VM DOMDocument ↔ host DOMDocument round-trip for XSLT (#3665). */
final class VmXslDomBridge
{
    public static function requireVmDocument(Variable $var, string $label): ObjectEntry
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $var->type) {
            throw new \TypeError(sprintf(
                '%s(): Argument #1 ($%s) must be of type DOMDocument, %s given',
                $label,
                str_contains($label, 'importStylesheet') ? 'stylesheet' : 'doc',
                VmDom::typeLabel($var)
            ));
        }
        $object = $var->toObject();
        if (VmDom::CLASS_DOCUMENT !== strtolower($object->class->name)) {
            throw new \TypeError(sprintf(
                '%s(): Argument #1 ($%s) must be of type DOMDocument, %s given',
                $label,
                str_contains($label, 'importStylesheet') ? 'stylesheet' : 'doc',
                $object->class->name
            ));
        }
        VmDom::ensureDocument($object);

        return $object;
    }

    public static function vmDocumentToHost(ObjectEntry $document): \DOMDocument
    {
        $host = new \DOMDocument();
        $xml = VmDom::saveXML($document);
        if (!$host->loadXML($xml)) {
            throw new \LogicException('Failed to serialize VM DOMDocument for XSLT bridge');
        }

        return $host;
    }

    public static function hostDocumentToVm(Context $ctx, \DOMDocument $host): ObjectEntry
    {
        $class = $ctx->classes[VmDom::CLASS_DOCUMENT] ?? null;
        if (null === $class) {
            throw new \LogicException('DOMDocument is not registered in this compiler build');
        }

        $entry = new ObjectEntry($class);
        $entry->constructed = true;
        VmDom::ensureDocument($entry);
        VmDom::ensureChildNodesList($ctx, $entry);
        $xml = $host->saveXML();
        if (!is_string($xml)) {
            throw new \LogicException('Host DOMDocument::saveXML() failed during XSLT bridge');
        }
        if (!VmDom::loadXML($ctx, $entry, $xml)) {
            throw new \LogicException('Failed to import host DOMDocument into VM DOM tree');
        }

        return $entry;
    }
}
