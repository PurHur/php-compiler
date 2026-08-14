<?php

declare(strict_types=1);

namespace PHPCompiler\ext\simplexml;

use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\Variable;

/** SimpleXMLElement::registerXPathNamespace — xpath prefix binding (php-src ext/simplexml/sxe.c; #18038). */
final class SimpleXmlElementRegisterXPathNamespace extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('registerXPathNamespace');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException('SimpleXMLElement::registerXPathNamespace() called without $this');
        }
        // php-src simplexml.stub.php: registerXPathNamespace(string $prefix, string $namespace) (#30828).
        $this->requireExactUserArgCount($frame, 'SimpleXMLElement::registerXPathNamespace', 2);
        $entry = VmSimpleXml::requireElement(
            $frame->calledArgs[0]->resolveIndirect()->toObject(),
            'SimpleXMLElement::registerXPathNamespace()'
        );
        $prefixVar = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_STRING !== $prefixVar->type) {
            throw new \TypeError('SimpleXMLElement::registerXPathNamespace(): Argument #1 ($prefix) must be of type string');
        }
        $nsVar = $frame->calledArgs[2]->resolveIndirect();
        if (Variable::TYPE_STRING !== $nsVar->type) {
            throw new \TypeError('SimpleXMLElement::registerXPathNamespace(): Argument #2 ($namespace) must be of type string');
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(VmSimpleXml::registerXPathNamespace(
                $entry,
                $prefixVar->toString(),
                $nsVar->toString()
            ));
        }
    }
}
