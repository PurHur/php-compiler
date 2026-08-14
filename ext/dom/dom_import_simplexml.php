<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** dom_import_simplexml() — SimpleXMLElement to DOMElement bridge (php-src ext/dom/node.c; #6057). */
final class dom_import_simplexml extends Internal
{
    public function __construct()
    {
        parent::__construct('dom_import_simplexml');
    }

    public function execute(Frame $frame): void
    {
        // php-src ext/dom/php_dom.stub.php: dom_import_simplexml(object $node) (#30828).
        $this->requireExactArgCount($frame, 'dom_import_simplexml', 1);
        if (null === $frame->vmContext) {
            throw new \LogicException('dom_import_simplexml() requires VM context');
        }

        $sxe = VmDomSimpleXmlBridge::requireSimpleXmlElement($frame->calledArgs[0], 'dom_import_simplexml');
        $element = VmDomSimpleXmlBridge::importSimpleXml($frame->vmContext, $sxe);

        if (null !== $frame->returnVar) {
            $frame->returnVar->object($element);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('dom_import_simplexml() is not JIT-lowered in this compiler build');
    }
}
