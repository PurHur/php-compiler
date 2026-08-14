<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * Dom\import_simplexml() — SimpleXMLElement to Dom\Element|Dom\Attr bridge
 * (php-src ext/dom/php_dom.c Dom_import_simplexml / PHP_LIBXML_CLASS_MODERN; #20711).
 *
 * Class name avoids case-collision with {@see dom_import_simplexml} (PHP class names
 * are case-insensitive).
 */
final class ns_import_simplexml extends Internal
{
    public function __construct()
    {
        parent::__construct('Dom\\import_simplexml');
    }

    public function execute(Frame $frame): void
    {
        // php-src php_dom.stub.php: Dom\import_simplexml(object $node) (#30828).
        $this->requireExactArgCount($frame, 'Dom\\import_simplexml', 1);
        if (null === $frame->vmContext) {
            throw new \LogicException('Dom\\import_simplexml() requires VM context');
        }

        $sxe = VmDomSimpleXmlBridge::requireSimpleXmlElement($frame->calledArgs[0], 'Dom\\import_simplexml');
        $element = VmDomSimpleXmlBridge::importSimpleXml($frame->vmContext, $sxe, true);

        if (null !== $frame->returnVar) {
            $frame->returnVar->object($element);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('Dom\\import_simplexml() is not JIT-lowered in this compiler build');
    }
}
