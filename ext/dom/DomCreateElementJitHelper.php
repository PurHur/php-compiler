<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/** DOMDocument::createElement() with optional value for JIT/AOT (#18938, #18951). */
final class DomCreateElementJitHelper
{
    public static function createElementArgv(
        Context $ctx,
        ObjectEntry $document,
        string $name,
        string $value = ''
    ): ObjectEntry {
        return VmDom::createElement($ctx, $name, $document, $value)->toObject();
    }

    public static function appendArgv1(Context $ctx, ObjectEntry $parent, Variable $a1): void
    {
        $a1 = $a1->resolveIndirect();
        if (Variable::TYPE_OBJECT === $a1->type) {
            VmDom::appendChildVariable($ctx, $parent, $a1->toObject());

            return;
        }
        VmDom::appendLiveStandardNodes($ctx, $parent, [$a1]);
    }

    public static function appendArgv2(Context $ctx, ObjectEntry $parent, Variable $a1, Variable $a2): void
    {
        VmDom::appendLiveStandardNodes($ctx, $parent, [$a1, $a2]);
    }

    public static function appendArgv3(Context $ctx, ObjectEntry $parent, Variable $a1, Variable $a2, Variable $a3): void
    {
        VmDom::appendLiveStandardNodes($ctx, $parent, [$a1, $a2, $a3]);
    }

    public static function prependArgv1(Context $ctx, ObjectEntry $parent, Variable $a1): void
    {
        VmDom::prependLiveStandardNodes($ctx, $parent, [$a1]);
    }

    public static function prependArgv2(Context $ctx, ObjectEntry $parent, Variable $a1, Variable $a2): void
    {
        VmDom::prependLiveStandardNodes($ctx, $parent, [$a1, $a2]);
    }

    public static function createDocumentFragmentArgv(Context $ctx, ObjectEntry $document): ObjectEntry
    {
        return VmDom::createDocumentFragment($ctx, $document)->toObject();
    }
}

