<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlwriter;

/** Per-instance XMLWriter buffer state (#6065). */
final class XmlWriterState
{
    public bool $open = false;

    /** @var ''|'memory'|'uri' */
    public string $mode = '';

    public ?string $uri = null;

    public string $buffer = '';

    /** @var list<string> */
    public array $elementStack = [];

    public bool $documentStarted = false;

    public ?string $version = null;

    public ?string $encoding = null;
}
