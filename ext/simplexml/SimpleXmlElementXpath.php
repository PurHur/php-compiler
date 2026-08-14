<?php

declare(strict_types=1);

namespace PHPCompiler\ext\simplexml;

use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\Variable;

/** SimpleXMLElement::xpath — descendant element query (php-src ext/simplexml/sxe.c; #18038). */
final class SimpleXmlElementXpath extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('xpath');
    }

    public function execute(Frame $frame): void
    {
        if (null === $frame->vmContext) {
            throw new \LogicException('SimpleXMLElement::xpath() requires VM context');
        }
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException('SimpleXMLElement::xpath() called without $this');
        }
        // php-src simplexml.stub.php: xpath(string $expression) (#30828).
        $this->requireExactUserArgCount($frame, 'SimpleXMLElement::xpath', 1);
        $entry = VmSimpleXml::requireElement(
            $frame->calledArgs[0]->resolveIndirect()->toObject(),
            'SimpleXMLElement::xpath()'
        );
        $pathVar = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_STRING !== $pathVar->type) {
            throw new \TypeError('SimpleXMLElement::xpath(): Argument #1 ($expression) must be of type string');
        }
        if (null !== $frame->returnVar) {
            $result = VmSimpleXml::xpath($frame->vmContext, $entry, $pathVar->toString(), $frame);
            if (false === $result) {
                $frame->returnVar->bool(false);
            } else {
                $frame->returnVar->array($result);
            }
        }
    }
}
