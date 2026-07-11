<?php

declare(strict_types=1);

namespace PHPCompiler\ext\simplexml;

/** In-memory XML element node for SimpleXML (php-src ext/simplexml/simplexml.c; #3338). */
final class SimpleXmlNodeState
{
    /** @param array<string, string> $attributes */
    public function __construct(
        public string $name,
        public array $attributes = [],
        /** @var list<SimpleXmlNodeState> */
        public array $children = [],
        public string $text = '',
    ) {
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
