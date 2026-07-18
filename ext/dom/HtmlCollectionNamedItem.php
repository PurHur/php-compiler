<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;

/**
 * Dom\HTMLCollection::namedItem() — VM (php-src ext/dom/html_collection.c; #20709).
 */
final class HtmlCollectionNamedItem extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('namedItem');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, VmDomLiving::CLASS_HTML_COLLECTION, 'Dom\\HTMLCollection::namedItem()');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('Dom\\HTMLCollection::namedItem() expects exactly 1 argument, 0 given');
        }
        $key = $this->stringArg($frame->calledArgs[1], 'Dom\\HTMLCollection::namedItem()', 0);
        if (null === $frame->returnVar) {
            return;
        }
        $node = VmDom::htmlCollectionNamedItem($receiver, $key);
        if (null === $node) {
            $frame->returnVar->null();

            return;
        }
        $frame->returnVar->object($node);
    }
}
