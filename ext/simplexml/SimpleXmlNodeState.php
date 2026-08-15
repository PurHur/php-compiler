<?php

declare(strict_types=1);

namespace PHPCompiler\ext\simplexml;

/** In-memory XML element node for SimpleXML (php-src ext/simplexml/simplexml.c; #3338). */
final class SimpleXmlNodeState
{
    /**
     * True after the node is unlinked from its document tree (xpath handles stay
     * alive but stringify empty — php-src sxe.c; #20483).
     */
    public bool $detached = false;

    /**
     * Document-order mixed content: text chunks and element children interleaved
     * (php-src xmlNode children / xmlNodeDump; #31049). `$text` remains the
     * concatenation of text chunks; `$children` remains element-only.
     *
     * @var list<string|SimpleXmlNodeState>
     */
    public array $content = [];

    /** @param array<string, string> $attributes */
    public function __construct(
        public string $name,
        public array $attributes = [],
        /** @var list<SimpleXmlNodeState> */
        public array $children = [],
        public string $text = '',
    ) {
        if ('' !== $this->text) {
            $this->content[] = $this->text;
        }
        foreach ($this->children as $child) {
            $this->content[] = $child;
        }
    }

    /** Clear payload after unlink so live xpath/property handles match Zend. */
    public function markDetached(): void
    {
        $this->detached = true;
        $this->text = '';
        $this->children = [];
        $this->content = [];
        $this->attributes = [];
    }

    /** Append a text chunk in document order (php-src xmlNodeDump mixed content; #31049). */
    public function appendText(string $chunk): void
    {
        if ('' === $chunk) {
            return;
        }
        $this->text .= $chunk;
        $lastIndex = [] === $this->content ? null : array_key_last($this->content);
        if (null !== $lastIndex && \is_string($this->content[$lastIndex])) {
            $this->content[$lastIndex] .= $chunk;

            return;
        }
        $this->content[] = $chunk;
    }

    /** Append an element child in document order (php-src xmlAddChild; #31049). */
    public function appendElement(self $child): void
    {
        $this->children[] = $child;
        $this->content[] = $child;
    }

    /**
     * Replace element payload with a single text node (php-src xmlNodeSetContent; #31049).
     */
    public function replaceText(string $text): void
    {
        $this->children = [];
        $this->text = $text;
        $this->content = '' === $text ? [] : [$text];
    }

    /** Unlink one element child from `$children` and mixed `$content`. */
    public function removeElement(self $target): bool
    {
        $found = false;
        foreach ($this->children as $index => $child) {
            if ($child === $target) {
                array_splice($this->children, $index, 1);
                $found = true;
                break;
            }
        }
        foreach ($this->content as $index => $part) {
            if ($part === $target) {
                array_splice($this->content, $index, 1);
                break;
            }
        }

        return $found;
    }

    /** Unlink every direct element child with the given QName. */
    public function removeElementsNamed(string $name): void
    {
        $this->children = array_values(array_filter(
            $this->children,
            static fn (self $child): bool => $child->name !== $name
        ));
        $this->content = array_values(array_filter(
            $this->content,
            static fn (string|self $part): bool => !$part instanceof self || $part->name !== $name
        ));
    }

    /** @return list<SimpleXmlNodeState> */
    public function elementsNamed(string $name): array
    {
        $out = [];
        foreach ($this->children as $child) {
            if ($child->name === $name) {
                $out[] = $child;
            }
        }

        return $out;
    }
}
