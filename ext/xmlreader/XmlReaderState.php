<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlreader;

/** Pull-parser cursor over pre-tokenized reader events. */
final class XmlReaderState
{
    public string $uri = '';

    public bool $closed = false;

    public bool $valid = true;

    /** @var list<XmlReaderEvent> */
    public array $events = [];

    /** Index of last returned event; -1 before first read(). */
    public int $position = -1;

    public ?XmlReaderEvent $current = null;
}
