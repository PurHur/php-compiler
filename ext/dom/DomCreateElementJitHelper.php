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

    public static function appendObjectArgv1(ObjectEntry $parent, ObjectEntry $child): void
    {
        VmDom::appendChild(VmDomJitFrame::vmContext(), $parent, $child);
    }

    public static function appendObjectArgv2(ObjectEntry $parent, ObjectEntry $a1, ObjectEntry $a2): void
    {
        self::appendObjectArgv1($parent, $a1);
        self::appendObjectArgv1($parent, $a2);
    }

    public static function appendObjectArgv3(ObjectEntry $parent, ObjectEntry $a1, ObjectEntry $a2, ObjectEntry $a3): void
    {
        $ctx = VmDomJitFrame::vmContext();
        VmDom::appendChild($ctx, $parent, $a1);
        $parentFresh = DomRegistry::entry($parent->id) ?? $parent;
        VmDom::appendChild($ctx, $parentFresh, $a2);
        $parentFresh = DomRegistry::entry($parent->id) ?? $parentFresh;
        VmDom::appendChild($ctx, $parentFresh, $a3);
    }

    public static function prependObjectArgv1(ObjectEntry $parent, ObjectEntry $child): void
    {
        VmDom::prependLiveStandardChild(VmDomJitFrame::vmContext(), $parent, $child);
    }

    public static function prependObjectArgv2(ObjectEntry $parent, ObjectEntry $a1, ObjectEntry $a2): void
    {
        VmDom::prependLiveStandardChild(VmDomJitFrame::vmContext(), $parent, $a2);
        VmDom::prependLiveStandardChild(VmDomJitFrame::vmContext(), $parent, $a1);
    }

    public static function appendArgv1(ObjectEntry $parent, Variable $a1): void
    {
        $ctx = VmDomJitFrame::vmContext();
        $a1 = $a1->resolveIndirect();
        if (Variable::TYPE_OBJECT === $a1->type) {
            VmDom::appendChildVariable($ctx, $parent, $a1->toObject());

            return;
        }
        VmDom::appendLiveStandardNodes($ctx, $parent, [$a1]);
    }

    public static function appendArgv2(ObjectEntry $parent, Variable $a1, Variable $a2): void
    {
        self::appendObjectArgv2(
            $parent,
            self::objectFromAppendVariable($a1),
            self::objectFromAppendVariable($a2)
        );
    }

    public static function appendArgv3(ObjectEntry $parent, Variable $a1, Variable $a2, Variable $a3): void
    {
        self::appendObjectArgv3(
            $parent,
            self::objectFromAppendVariable($a1),
            self::objectFromAppendVariable($a2),
            self::objectFromAppendVariable($a3)
        );
    }

    public static function prependArgv1(ObjectEntry $parent, Variable $a1): void
    {
        VmDom::prependLiveStandardNodes(VmDomJitFrame::vmContext(), $parent, [$a1]);
    }

    public static function prependArgv2(ObjectEntry $parent, Variable $a1, Variable $a2): void
    {
        VmDom::prependLiveStandardNodes(VmDomJitFrame::vmContext(), $parent, [$a1, $a2]);
    }

    public static function createDocumentFragmentArgv(Context $ctx, ObjectEntry $document): ObjectEntry
    {
        return VmDom::createDocumentFragment($ctx, $document)->toObject();
    }

    public static function createDocumentFragmentObjectArgv(ObjectEntry $document): ObjectEntry
    {
        return VmDom::createDocumentFragment(VmDomJitFrame::vmContext(), $document)->toObject();
    }

    private static function objectFromAppendVariable(Variable $arg): ObjectEntry
    {
        $arg = $arg->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $arg->type) {
            throw new \DOMException('Hierarchy request error');
        }

        return $arg->toObject();
    }
}

