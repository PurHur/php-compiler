<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/**
 * DOMNode::C14N() helper for user-script AOT (#19467, #22378).
 *
 * Receivers from user-script AOT are often shadow ObjectEntries (id not in DomRegistry).
 * Resolve via {@see DomRegistry::entry()} before calling VmDom — never pass an unregistered
 * shadow into nested-compiled VmDom::c14n (corrupt string / abort).
 *
 * Returns a boxed Variable so relative-NS failure can be boolean false (not empty string).
 */
final class DomC14NJitHelper
{
    public static function c14nArgv(Context $ctx, ObjectEntry $node, int $exclusive): Variable
    {
        $out = new Variable();
        $canonical = DomRegistry::entry($node->id);
        if (null === $canonical) {
            $out->string('');

            return $out;
        }
        $payload = VmDom::c14n($ctx, $canonical, 0 !== $exclusive, false, null, null, null, 'DOMNode::C14N');
        if (false === $payload) {
            $out->bool(false);
        } else {
            $out->string($payload);
        }

        return $out;
    }
}
