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
        $ctx = VmDomJitFrame::vmContext();
        $canonical = DomRegistry::entry($parent->id) ?? $parent;
        VmDom::appendChild($ctx, $canonical, $child);
        if ($canonical !== $parent) {
            VmDom::mirrorNodeLinkProperties($parent, $canonical);
        }
    }

    public static function appendObjectArgv2(ObjectEntry $parent, ObjectEntry $a1, ObjectEntry $a2): void
    {
        self::appendObjectArgv1($parent, $a1);
        self::appendObjectArgv1($parent, $a2);
    }

    public static function appendObjectArgv3(ObjectEntry $parent, ObjectEntry $a1, ObjectEntry $a2, ObjectEntry $a3): void
    {
        self::appendObjectArgv1($parent, $a1);
        self::appendObjectArgv1($parent, $a2);
        self::appendObjectArgv1($parent, $a3);
    }

    public static function prependObjectArgv1(ObjectEntry $parent, ObjectEntry $child): void
    {
        $ctx = VmDomJitFrame::vmContext();
        $canonical = DomRegistry::entry($parent->id) ?? $parent;
        VmDom::prependLiveStandardChild($ctx, $canonical, $child);
        if ($canonical !== $parent) {
            VmDom::mirrorNodeLinkProperties($parent, $canonical);
        }
    }

    public static function prependObjectArgv2(ObjectEntry $parent, ObjectEntry $a1, ObjectEntry $a2): void
    {
        self::prependObjectArgv1($parent, $a2);
        self::prependObjectArgv1($parent, $a1);
    }

    public static function appendArgv1(ObjectEntry $parent, Variable $a1): void
    {
        $ctx = VmDomJitFrame::vmContext();
        $a1 = $a1->resolveIndirect();
        if (Variable::TYPE_OBJECT === $a1->type) {
            self::appendObjectArgv1($parent, $a1->toObject());

            return;
        }
        $canonical = DomRegistry::entry($parent->id) ?? $parent;
        VmDom::appendLiveStandardNodes($ctx, $canonical, [$a1]);
        if ($canonical !== $parent) {
            VmDom::mirrorNodeLinkProperties($parent, $canonical);
        }
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
        $ctx = VmDomJitFrame::vmContext();
        $canonical = DomRegistry::entry($parent->id) ?? $parent;
        VmDom::prependLiveStandardNodes($ctx, $canonical, [$a1]);
        if ($canonical !== $parent) {
            VmDom::mirrorNodeLinkProperties($parent, $canonical);
        }
    }

    public static function prependArgv2(ObjectEntry $parent, Variable $a1, Variable $a2): void
    {
        self::prependObjectArgv2($parent, $a1, $a2);
    }

    public static function createDocumentFragmentArgv(Context $ctx, ObjectEntry $document): ObjectEntry
    {
        return VmDom::createDocumentFragment($ctx, $document)->toObject();
    }

    public static function createDocumentFragmentObjectArgv(ObjectEntry $document): ObjectEntry
    {
        return VmDom::createDocumentFragment(VmDomJitFrame::vmContext(), $document)->toObject();
    }

    public static function firstChildByIdArgv(int $nodeId): ?ObjectEntry
    {
        return self::childByRegistryIdArgv($nodeId, true);
    }

    public static function lastChildByIdArgv(int $nodeId): ?ObjectEntry
    {
        return self::childByRegistryIdArgv($nodeId, false);
    }

    private static function childByRegistryIdArgv(int $nodeId, bool $first): ?ObjectEntry
    {
        $node = DomRegistry::entry($nodeId);
        if (null === $node || !DomRegistry::has($node)) {
            return null;
        }
        $childIds = DomRegistry::state($node)->childIds;
        if ([] === $childIds) {
            return null;
        }
        $childId = $first ? $childIds[0] : $childIds[\count($childIds) - 1];

        return DomRegistry::entry($childId);
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

