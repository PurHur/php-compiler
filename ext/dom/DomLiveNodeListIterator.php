<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\ext\spl\InternalIteratorLiveHandler;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/**
 * Live InternalIterator backing for DOMNodeList (php-src ext/dom/nodelist.c; #21930).
 *
 * Zend does not snapshot getIterator() into a fixed HashTable:
 * - getElementsByTagName* / class queries: index stays put while membership refreshes
 *   (remove at index 0 → next yields former index 1, now at 0's neighbor → skip).
 * - childNodes (and other non-query lists): removing the current node ends iteration
 *   (libxml node->next after unlink is NULL).
 */
final class DomLiveNodeListIterator implements InternalIteratorLiveHandler
{
    private int $pos = 0;

    private ?int $currentId = null;

    public function __construct(
        private ObjectEntry $nodeList,
        private bool $indexLive,
    ) {
    }

    public static function forNodeList(ObjectEntry $nodeList): self
    {
        $state = DomRegistry::state($nodeList);
        // Live tag/class queries keep a stable index across refresh (#21930).
        $indexLive = null !== $state->listQueryRootId;

        return new self($nodeList, $indexLive);
    }

    public function rewind(): void
    {
        $this->pos = 0;
        $this->currentId = null;
    }

    public function next(): void
    {
        if ($this->indexLive) {
            ++$this->pos;
            $this->currentId = null;

            return;
        }

        VmDom::refreshNodeListIfLive($this->nodeList);
        $ids = DomRegistry::state($this->nodeList)->listNodeIds;
        // childNodes: removing the current node ends the walk (php-src node->next after unlink).
        if (null !== $this->currentId && !\in_array($this->currentId, $ids, true)) {
            $this->pos = \count($ids);
            $this->currentId = null;

            return;
        }

        ++$this->pos;
        $this->currentId = null;
    }

    public function valid(): bool
    {
        VmDom::refreshNodeListIfLive($this->nodeList);

        return $this->pos >= 0
            && $this->pos < \count(DomRegistry::state($this->nodeList)->listNodeIds);
    }

    public function current(): Variable
    {
        $var = new Variable();
        $node = VmDom::nodeListItem($this->nodeList, $this->pos);
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
        return $this->pos;
    }
}
