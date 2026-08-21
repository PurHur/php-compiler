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
 */
final class DomElementRemoveAttributeNS implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_removeattrns_invoke_cont');
        $id = null;
        $removed = [];
        if (\count($args) >= 3) {
            $local = $args[2]->compileTimeString;
            $nsKnown = $args[1]->isNullConstant || null !== $args[1]->compileTimeString;
            if (null !== $local && $nsKnown && 'xmlns' !== $local) {
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
                } else {
                    foreach ($removed as $name) {
                        JitDomLoadXMLUserScript::refreshCompileTimeXmlRootAttributeRemove($name);
                    }
                }
            }
        }

        $result = JitDomAttributeNodeNS::invokeRemoveAttributeNS($context, ...$args);

        if ([] !== $removed || (null !== $id && \count($args) >= 3)) {
            $local = $args[2]->compileTimeString ?? null;
            $nsKnown = isset($args[1]) && ($args[1]->isNullConstant || null !== $args[1]->compileTimeString);
            if (null !== $local && $nsKnown && 'xmlns' !== $local) {
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
     * @param array<string, string> $attrs
     *
     * @return list<string> removed open-tag keys (qName and orphaned xmlns:prefix)
     */
    private static function removeLocalFromBag(array &$attrs, string $localName): array
    {
        $removed = [];
        $prefixes = [];
        foreach (array_keys($attrs) as $name) {
            if (str_starts_with($name, 'xmlns')) {
                continue;
            }
            $local = str_contains($name, ':') ? substr($name, (int) strrpos($name, ':') + 1) : $name;
            if ($local !== $localName) {
                continue;
            }
            if (str_contains($name, ':')) {
                $prefixes[substr($name, 0, (int) strpos($name, ':'))] = true;
            }
            unset($attrs[$name]);
            $removed[] = $name;
        }
        foreach (array_keys($prefixes) as $prefix) {
            if ('' === $prefix) {
                continue;
            }
            $stillUsed = false;
            foreach (array_keys($attrs) as $name) {
                if (str_starts_with($name, $prefix.':')) {
                    $stillUsed = true;
                    break;
                }
            }
            if ($stillUsed) {
                continue;
            }
            $xmlns = 'xmlns:'.$prefix;
            if (isset($attrs[$xmlns])) {
                unset($attrs[$xmlns]);
                $removed[] = $xmlns;
            }
        }

        return $removed;
    }
}
