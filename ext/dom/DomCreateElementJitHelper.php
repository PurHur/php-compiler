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

    /** DOMNode::removeChild() — user-script AOT (#19240, php-src ext/dom/node.c). */
    public static function removeChildObjectArgv1(ObjectEntry $parent, ObjectEntry $child): void
    {
        $ctx = VmDomJitFrame::vmContext();
        $canonical = DomRegistry::entry($parent->id) ?? $parent;
        $childCanon = DomRegistry::entry($child->id) ?? $child;
        VmDom::removeChild($ctx, $canonical, $childCanon);
        if ($canonical !== $parent) {
            VmDom::mirrorNodeLinkProperties($parent, $canonical);
        }
    }

    /** DOMNode::replaceChild() — user-script AOT (#19240, #22678, php-src ext/dom/node.c). */
    public static function replaceChildObjectArgv2(
        ObjectEntry $parent,
        ObjectEntry $newChild,
        ObjectEntry $oldChild
    ): void {
        $ctx = VmDomJitFrame::vmContext();
        $canonical = DomRegistry::entry($parent->id) ?? $parent;
        $newCanon = DomRegistry::entry($newChild->id) ?? $newChild;
        $oldCanon = DomRegistry::entry($oldChild->id) ?? $oldChild;
        VmDom::replaceChild($ctx, $canonical, $newCanon, $oldCanon);
        if ($canonical !== $parent) {
            VmDom::mirrorNodeLinkProperties($parent, $canonical);
        }
    }

    /** DOMNode::insertBefore() — user-script AOT (#22686, php-src ext/dom/node.c). */
    public static function insertBeforeObjectArgv2(
        ObjectEntry $parent,
        ObjectEntry $newChild,
        ObjectEntry $refChild
    ): void {
        $ctx = VmDomJitFrame::vmContext();
        $canonical = DomRegistry::entry($parent->id) ?? $parent;
        $newCanon = DomRegistry::entry($newChild->id) ?? $newChild;
        $refCanon = DomRegistry::entry($refChild->id) ?? $refChild;
        VmDom::insertBefore($ctx, $canonical, $newCanon, $refCanon);
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

    public static function appendStringArgv1(ObjectEntry $parent, string $text): void
    {
        $ctx = VmDomJitFrame::vmContext();
        $canonical = DomRegistry::entry($parent->id) ?? $parent;
        $owner = VmDom::ownerDocumentEntry($canonical);
        if (null === $owner && VmDom::isDocument($canonical)) {
            $owner = $canonical;
        }
        $child = VmDom::createTextNode($ctx, $text, $owner);
        VmDom::appendLiveStandardChild($ctx, $canonical, $child);
        VmDom::syncSubtree($ctx, $canonical);
        if ($canonical !== $parent) {
            VmDom::mirrorNodeLinkProperties($parent, $canonical);
        }
    }

    public static function prependStringArgv1(ObjectEntry $parent, string $text): void
    {
        $ctx = VmDomJitFrame::vmContext();
        $canonical = DomRegistry::entry($parent->id) ?? $parent;
        $owner = VmDom::ownerDocumentEntry($canonical);
        if (null === $owner && VmDom::isDocument($canonical)) {
            $owner = $canonical;
        }
        $child = VmDom::createTextNode($ctx, $text, $owner);
        VmDom::prependLiveStandardChild($ctx, $canonical, $child);
        VmDom::syncSubtree($ctx, $canonical);
        if ($canonical !== $parent) {
            VmDom::mirrorNodeLinkProperties($parent, $canonical);
        }
    }

    public static function appendArgv1(ObjectEntry $parent, Variable $a1): void
    {
        $ctx = VmDomJitFrame::vmContext();
        $a1 = $a1->resolveIndirect();
        if (Variable::TYPE_OBJECT === $a1->type) {
            self::appendObjectArgv1($parent, $a1->toObject());

            return;
        }
        if (Variable::TYPE_STRING === $a1->type) {
            self::appendStringArgv1($parent, $a1->toString($ctx));

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
        $a1 = $a1->resolveIndirect();
        if (Variable::TYPE_STRING === $a1->type) {
            self::prependStringArgv1($parent, $a1->toString($ctx));

            return;
        }
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

    public static function replaceChildrenArgv0(ObjectEntry $parent): void
    {
        self::replaceChildrenObjectArgv0($parent);
    }

    public static function replaceChildrenArgv1(ObjectEntry $parent, Variable $a1): void
    {
        self::replaceChildrenFromResolved($parent, [$a1->resolveIndirect()]);
    }

    public static function replaceChildrenArgv2(ObjectEntry $parent, Variable $a1, Variable $a2): void
    {
        self::replaceChildrenFromResolved($parent, [
            $a1->resolveIndirect(),
            $a2->resolveIndirect(),
        ]);
    }

    public static function replaceChildrenArgv3(ObjectEntry $parent, Variable $a1, Variable $a2, Variable $a3): void
    {
        self::replaceChildrenFromResolved($parent, [
            $a1->resolveIndirect(),
            $a2->resolveIndirect(),
            $a3->resolveIndirect(),
        ]);
    }

    public static function replaceChildrenArgv4(
        ObjectEntry $parent,
        Variable $a1,
        Variable $a2,
        Variable $a3,
        Variable $a4
    ): void {
        self::replaceChildrenFromResolved($parent, [
            $a1->resolveIndirect(),
            $a2->resolveIndirect(),
            $a3->resolveIndirect(),
            $a4->resolveIndirect(),
        ]);
    }

    public static function replaceChildrenObjectArgv0(ObjectEntry $parent): void
    {
        $ctx = VmDomJitFrame::vmContext();
        $canonical = DomRegistry::entry($parent->id) ?? $parent;
        VmDom::replaceChildrenLiveStandardObjects($ctx, $canonical);
        if ($canonical !== $parent) {
            VmDom::mirrorNodeLinkProperties($parent, $canonical);
        }
    }

    public static function replaceChildrenObjectArgv1(ObjectEntry $parent, ObjectEntry $a1): void
    {
        $ctx = VmDomJitFrame::vmContext();
        $canonical = DomRegistry::entry($parent->id) ?? $parent;
        VmDom::replaceChildrenLiveStandardObjects($ctx, $canonical, $a1);
        if ($canonical !== $parent) {
            VmDom::mirrorNodeLinkProperties($parent, $canonical);
        }
    }

    public static function replaceChildrenObjectArgv2(ObjectEntry $parent, ObjectEntry $a1, ObjectEntry $a2): void
    {
        $ctx = VmDomJitFrame::vmContext();
        $canonical = DomRegistry::entry($parent->id) ?? $parent;
        VmDom::replaceChildrenLiveStandardObjects($ctx, $canonical, $a1, $a2);
        if ($canonical !== $parent) {
            VmDom::mirrorNodeLinkProperties($parent, $canonical);
        }
    }

    public static function replaceChildrenObjectArgv3(ObjectEntry $parent, ObjectEntry $a1, ObjectEntry $a2, ObjectEntry $a3): void
    {
        $ctx = VmDomJitFrame::vmContext();
        $canonical = DomRegistry::entry($parent->id) ?? $parent;
        VmDom::replaceChildrenLiveStandardObjects($ctx, $canonical, $a1, $a2, $a3);
        if ($canonical !== $parent) {
            VmDom::mirrorNodeLinkProperties($parent, $canonical);
        }
    }

    public static function replaceChildrenObjectArgv4(ObjectEntry $parent, ObjectEntry $a1, ObjectEntry $a2, ObjectEntry $a3, ObjectEntry $a4): void
    {
        $ctx = VmDomJitFrame::vmContext();
        $canonical = DomRegistry::entry($parent->id) ?? $parent;
        VmDom::replaceChildrenLiveStandardObjects($ctx, $canonical, $a1, $a2, $a3, $a4);
        if ($canonical !== $parent) {
            VmDom::mirrorNodeLinkProperties($parent, $canonical);
        }
    }

    public static function replaceChildrenStringArgv1(ObjectEntry $parent, string $text): void
    {
        $ctx = VmDomJitFrame::vmContext();
        $canonical = DomRegistry::entry($parent->id) ?? $parent;
        $owner = VmDom::ownerDocumentEntry($canonical);
        if (null === $owner && VmDom::isDocument($canonical)) {
            $owner = $canonical;
        }
        $child = VmDom::createTextNode($ctx, $text, $owner);
        self::replaceChildrenObjectArgv1($parent, $child);
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

    /**
     * @param list<Variable> $resolved
     */
    private static function replaceChildrenFromResolved(ObjectEntry $parent, array $resolved): void
    {
        $ctx = VmDomJitFrame::vmContext();
        $canonical = DomRegistry::entry($parent->id) ?? $parent;
        // Avoid foreach over Variable ...$args — NestedJIT mis-lowers it as object-property
        // foreach on PHPCompiler\VM\Variable (0 visible props after #23430; #24247).
        VmDom::replaceChildrenLiveStandardNodes($ctx, $canonical, $resolved);
        if ($canonical !== $parent) {
            VmDom::mirrorNodeLinkProperties($parent, $canonical);
        }
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

