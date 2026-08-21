<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\dom\JitDomAttributeNodeNS;
use PHPCompiler\ext\dom\JitDomCreateElementAttrs;
use PHPCompiler\ext\dom\JitDomLoadXMLUserScript;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/**
 * DOMElement::setAttributeNS() — user-script AOT (#32398, php-src xmlSetNsProp).
 *
 * Syncs PROP_USER_SCRIPT_XMLNS_ATTR like {@see DomElementSetAttribute} (#33526 / peer #33509).
 */
final class DomElementSetAttributeNS implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_setattrns_invoke_cont');
        $id = null;
        if (\count($args) >= 4) {
            $qname = $args[2]->compileTimeString;
            $value = $args[3]->compileTimeString;
            $nsKnown = $args[1]->isNullConstant || null !== $args[1]->compileTimeString;
            if (null !== $qname && null !== $value && $nsKnown
                && 'xmlns' !== $qname && !str_starts_with($qname, 'xmlns:')) {
                $ns = $args[1]->isNullConstant ? null : $args[1]->compileTimeString;
                $id = $args[0]->compileTimeDomElementId ?? JitDomCreateElementAttrs::lastId();
                $bagUpdates = self::openTagAttrUpdates($ns, $qname, $value);
                $attrs = $args[0]->compileTimeDomAttributes ?? [];
                if ([] === $attrs && null !== $id) {
                    $attrs = JitDomCreateElementAttrs::get($id);
                }
                // Rebuild so xmlns:prefix stays before the prefixed Attr (Zend serialize order).
                foreach ($bagUpdates as $name => $val) {
                    unset($attrs[$name]);
                }
                $attrs = $bagUpdates + $attrs;
                if (null !== $id) {
                    foreach ($bagUpdates as $name => $val) {
                        JitDomCreateElementAttrs::set($id, $name, $val);
                    }
                    if (null === $args[0]->compileTimeDomElementId) {
                        $args[0]->compileTimeDomElementId = $id;
                    }
                }
                $args[0]->compileTimeDomAttributes = $attrs;

                $path = $args[0]->compileTimeDomNodePath ?? null;
                $nested = null !== $path && '' !== $path
                    && substr_count(trim($path, '/'), '/') >= 1;
                if ($nested) {
                    JitDomLoadXMLUserScript::markTreeMutatedSinceLoad();
                } else {
                    foreach ($bagUpdates as $name => $val) {
                        JitDomLoadXMLUserScript::refreshCompileTimeXmlRootAttributeSet($name, $val);
                    }
                }
            }
        }

        $result = JitDomAttributeNodeNS::invokeSetAttributeNS($context, ...$args);

        if (\count($args) >= 4) {
            $qname = $args[2]->compileTimeString;
            $value = $args[3]->compileTimeString;
            $nsKnown = $args[1]->isNullConstant || null !== $args[1]->compileTimeString;
            if (null !== $qname && null !== $value && $nsKnown
                && 'xmlns' !== $qname && !str_starts_with($qname, 'xmlns:')) {
                $attrs = $args[0]->compileTimeDomAttributes;
                if (null === $attrs && null !== $id) {
                    $attrs = JitDomCreateElementAttrs::get($id);
                }
                if (null !== $attrs) {
                    JitDomAttributeNodeNS::syncSaveXmlAttrSuffix($context, $args[0], $attrs);
                }
            }
        }

        return $result;
    }

    /**
     * Open-tag keys for saveXML: qName=value plus xmlns:prefix when prefixed (php-src xmlSetNsProp).
     *
     * @return array<string, string>
     */
    private static function openTagAttrUpdates(?string $namespace, string $qname, string $value): array
    {
        // php-src emits xmlns:prefix before the prefixed Attr in serialization.
        $updates = [];
        $colon = strpos($qname, ':');
        if (false !== $colon && null !== $namespace && '' !== $namespace) {
            $prefix = substr($qname, 0, $colon);
            if ('' !== $prefix && 'xmlns' !== $prefix) {
                $updates['xmlns:'.$prefix] = $namespace;
            }
        }
        $updates[$qname] = $value;

        return $updates;
    }
}
