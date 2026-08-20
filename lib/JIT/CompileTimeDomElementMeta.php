<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

/**
 * Shared createElement compile-time state for C14N fold (#32964 / #32973).
 *
 * Held by reference on {@see Variable::$compileTimeDomElementMeta} so setAttribute /
 * appendChild on receiver temps update the same bag the later C14N call reads.
 */
final class CompileTimeDomElementMeta
{
    public string $tagName = '';

    /** @var \ArrayObject<string, string> */
    public \ArrayObject $attributes;

    /** True after appendChild/insertBefore (libxml orphans → ""; #19741). */
    public bool $connected = false;

    /** Escaped inner XML from createElement($name, $value). */
    public string $innerXml = '';

    public function __construct(string $tagName = '', string $innerXml = '')
    {
        $this->tagName = $tagName;
        $this->innerXml = $innerXml;
        $this->attributes = new \ArrayObject();
    }
}
