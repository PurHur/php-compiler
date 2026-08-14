<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;

/**
 * DOMDocument::getElementsByTagNameNS() — VM (php-src ext/dom/php_dom.c; #14454).
 *
 * Exact user arity 2 — Zend ArgumentCountError (#31032; missed by #31011).
 */
final class DocumentGetElementsByTagNameNS extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('getElementsByTagNameNS');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactUserArgCount($frame, 'DOMDocument::getElementsByTagNameNS', 2);
        $receiver = $this->receiver($frame, VmDom::CLASS_DOCUMENT, 'DOMDocument::getElementsByTagNameNS()');
        // php-src stub: ?string $namespace — null must not TypeError under strict (#30091).
        $namespace = $this->nullableStringArg(
            $frame->calledArgs[1],
            'DOMDocument::getElementsByTagNameNS()',
            0
        );
        $localName = $this->stringArg(
            $frame->calledArgs[2],
            'DOMDocument::getElementsByTagNameNS()',
            1,
            $frame,
            'localName'
        );
        if (null === $frame->vmContext) {
            throw new \LogicException('DOMDocument::getElementsByTagNameNS() requires VM context in this compiler build');
        }
        // Zend treats null namespace like "" for matching (no-namespace elements).
        $list = VmDom::getElementsByTagNameNS($frame->vmContext, $receiver, $namespace ?? '', $localName);
        if (null !== $frame->returnVar) {
            $frame->returnVar->copyFrom($list);
        }
    }
}
