<?php

declare(strict_types=1);

namespace PHPCompiler\ext\simplexml;

use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\Variable;

/** SimpleXMLElement::addChild — append element child (php-src ext/simplexml/sxe.c; #18038). */
final class SimpleXmlElementAddChild extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('addChild');
    }

    public function execute(Frame $frame): void
    {
        if (null === $frame->vmContext) {
            throw new \LogicException('SimpleXMLElement::addChild() requires VM context');
        }
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException('SimpleXMLElement::addChild() called without $this');
        }
        // php-src simplexml.stub.php: addChild(string $qualifiedName, ?string $value = null, ?string $namespace = null) (#30828).
        $this->requireUserArgCountRange($frame, 'SimpleXMLElement::addChild', 1, 3);
        $entry = VmSimpleXml::requireElement(
            $frame->calledArgs[0]->resolveIndirect()->toObject(),
            'SimpleXMLElement::addChild()'
        );
        $nameVar = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_STRING !== $nameVar->type) {
            throw new \TypeError('SimpleXMLElement::addChild(): Argument #1 ($qualifiedName) must be of type string');
        }
        $value = null;
        if (\count($frame->calledArgs) >= 3) {
            $valueVar = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_NULL !== $valueVar->type) {
                if (Variable::TYPE_STRING !== $valueVar->type) {
                    throw new \TypeError('SimpleXMLElement::addChild(): Argument #2 ($value) must be of type ?string');
                }
                $value = $valueVar->toString();
            }
        }
        $namespace = null;
        if (\count($frame->calledArgs) >= 4) {
            $nsVar = $frame->calledArgs[3]->resolveIndirect();
            if (Variable::TYPE_NULL !== $nsVar->type) {
                if (Variable::TYPE_STRING !== $nsVar->type) {
                    throw new \TypeError('SimpleXMLElement::addChild(): Argument #3 ($namespace) must be of type ?string');
                }
                $namespace = $nsVar->toString();
            }
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->object(VmSimpleXml::addChild(
                $frame->vmContext,
                $entry,
                $nameVar->toString(),
                $value,
                $namespace
            ));
        }
    }
}
