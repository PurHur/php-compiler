<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;

/** DOMDocumentFragment::__construct() — VM (php-src ext/dom/php_dom.c; #17617). */
final class FragmentConstruct extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, VmDom::CLASS_DOCUMENT_FRAGMENT, 'DOMDocumentFragment::__construct()');
        VmDom::ensureDocumentFragment($receiver);
        if (null !== $frame->vmContext) {
            VmDom::ensureChildNodesList($frame->vmContext, $receiver);
        }
    }
}
