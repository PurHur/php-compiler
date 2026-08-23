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
 * DOMElement::removeAttributeNS() — user-script AOT (#32398, php-src returns null).
 *
 * Drops NS attrs from the saveXML open-tag bag (#33526 / peer #33509).
 * loadXML roots: also refresh compile-time XML + PROP_USER_SCRIPT_XMLNS_ATTR (#34257).
 */
final class DomElementRemoveAttributeNS implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_removeattrns_invoke_cont');
        $id = null;
        $removed = [];
        $local = null;
        $hadLoadXml = null !== JitDomLoadXMLUserScript::lastCompileTimeXml();
        $didRefreshRootXml = false;
        if (\count($args) >= 3) {
            $local = $args[2]->compileTimeString;
            $nsKnown = $args[1]->isNullConstant || null !== $args[1]->compileTimeString;
            if (null !== $local && $nsKnown && 'xmlns' !== $local) {
                $ns = $args[1]->isNullConstant ? null : $args[1]->compileTimeString;
                $id = $args[0]->compileTimeDomElementId ?? JitDomCreateElementAttrs::lastId();
                $attrs = $args[0]->compileTimeDomAttributes ?? [];
                if (null !== $id) {
                    foreach (JitDomCreateElementAttrs::get($id) as $name => $value) {
                        if (!isset($attrs[$name])) {
                            $attrs[$name] = $value;
                        }
                    }
                }
                $removed = self::removeLocalFromBag($attrs, $local);
                if (null !== $id) {
                    foreach ($removed as $name) {
                        JitDomCreateElementAttrs::remove($id, $name);
                    }
                }
                $args[0]->compileTimeDomAttributes = $attrs;

                $path = $args[0]->compileTimeDomNodePath ?? null;
                $nested = null !== $path && '' !== $path
                    && substr_count(trim($path, '/'), '/') >= 1;
                if ($nested) {
                    JitDomLoadXMLUserScript::markTreeMutatedSinceLoad();
                } elseif ($hadLoadXml) {
                    // Always mutate host XML — bag is empty for loadXML-seeded attrs (#34257).
                    JitDomLoadXMLUserScript::refreshCompileTimeXmlRootAttributeRemoveNS($ns, $local);
                    $didRefreshRootXml = true;
                } else {
                    foreach ($removed as $name) {
                        JitDomLoadXMLUserScript::refreshCompileTimeXmlRootAttributeRemove($name);
                    }
                }
            }
        }

        $result = JitDomAttributeNodeNS::invokeRemoveAttributeNS($context, ...$args);

        if (null !== $local && 'xmlns' !== $local) {
            $nsKnown = isset($args[1]) && ($args[1]->isNullConstant || null !== $args[1]->compileTimeString);
            if ($nsKnown) {
                if ($didRefreshRootXml) {
                    JitDomLoadXMLUserScript::syncElementXmlnsAttrFromCompileTimeXml($context, $args[0]);
                } else {
                    $attrs = $args[0]->compileTimeDomAttributes;
                    if (null === $attrs && null !== $id) {
                        $attrs = JitDomCreateElementAttrs::get($id);
                    }
                    if (null !== $attrs) {
                        JitDomAttributeNodeNS::syncSaveXmlAttrSuffix($context, $args[0], $attrs);
                    }
                }
            }
        }

        return $result;
    }

    /**
     * @param array<string, string> $attrs
     *
     * @return list<string> removed open-tag keys (qName only — keep xmlns:prefix like Zend)
     */
    private static function removeLocalFromBag(array &$attrs, string $localName): array
    {
        $removed = [];
        foreach (array_keys($attrs) as $name) {
            if (str_starts_with($name, 'xmlns')) {
                continue;
            }
            $local = str_contains($name, ':') ? substr($name, (int) strrpos($name, ':') + 1) : $name;
            if ($local !== $localName) {
                continue;
            }
            unset($attrs[$name]);
            $removed[] = $name;
        }

        return $removed;
    }
}
