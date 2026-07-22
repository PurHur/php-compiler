<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\ext\spl\InternalIteratorLiveHandler;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/**
 * Live InternalIterator backing for DOMNamedNodeMap (php-src ext/dom/nodemap.c; #21931).
 *
 * Zend walks the live libxml attribute chain: removing the current Attr ends iteration
 * (attr->next after unlink is NULL). Removing a later/earlier Attr updates membership;
 * the next step advances from the still-attached current node (not a frozen snapshot index).
 */
final class DomLiveNamedNodeMapIterator implements InternalIteratorLiveHandler
{
    private int $pos = 0;

    private ?int $currentId = null;

    public function __construct(
        private ObjectEntry $namedNodeMap,
    ) {
    }

    public static function forNamedNodeMap(ObjectEntry $namedNodeMap): self
    {
        return new self($namedNodeMap);
    }

    public function rewind(): void
    {
        $this->pos = 0;
        $this->currentId = null;
    }

    public function next(): void
    {
        $ids = DomRegistry::state($this->namedNodeMap)->listNodeIds;
        // Removing the current Attr ends the walk (php-src / libxml attr->next after unlink).
        if (null !== $this->currentId && !\in_array($this->currentId, $ids, true)) {
            $this->pos = \count($ids);
            $this->currentId = null;

            return;
        }

        if (null !== $this->currentId) {
            $idx = \array_search($this->currentId, $ids, true);
            if (false !== $idx) {
                $this->pos = $idx + 1;
                $this->currentId = null;

                return;
            }
        }

        ++$this->pos;
        $this->currentId = null;
    }

    public function valid(): bool
    {
        return $this->pos >= 0
            && $this->pos < \count(DomRegistry::state($this->namedNodeMap)->listNodeIds);
    }

    public function current(): Variable
    {
        $var = new Variable();
        $node = VmDom::namedNodeMapItem($this->namedNodeMap, $this->pos);
        if (null === $node) {
            $this->currentId = null;
            $var->null();

            return $var;
        }
        $this->currentId = $node->id;
        $var->object($node);

        return $var;
    }

    public function key(): int|string
    {
        $node = VmDom::namedNodeMapItem($this->namedNodeMap, $this->pos);
        if (null === $node) {
            return $this->pos;
        }
        // php-src NamedNodeMap iteration keys Attr.name (local), not nodeName (QName).
        if (VmDom::isAttr($node)) {
            return VmDom::readLocalName($node);
        }

        return DomRegistry::state($node)->nodeName;
    }
}
