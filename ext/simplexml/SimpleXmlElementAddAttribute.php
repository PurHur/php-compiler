<?php

declare(strict_types=1);

namespace PHPCompiler\ext\simplexml;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\Variable;

/** SimpleXMLElement::addAttribute — add element attribute (php-src ext/simplexml/sxe.c; #19307 / #31554). */
final class SimpleXmlElementAddAttribute extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('addAttribute');
    }

    public function execute(Frame $frame): void
    {
        if (null === $frame->vmContext) {
            throw new \LogicException('SimpleXMLElement::addAttribute() requires VM context');
        }
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException('SimpleXMLElement::addAttribute() called without $this');
        }
        // php-src simplexml.stub.php: addAttribute(string $qualifiedName, string $value = "", ?string $namespace = null) (#30828).
        $this->requireUserArgCountRange($frame, 'SimpleXMLElement::addAttribute', 2, 3);
        $entry = VmSimpleXml::requireElement(
            $frame->calledArgs[0]->resolveIndirect()->toObject(),
            'SimpleXMLElement::addAttribute()'
        );
        // Z_PARAM_STR $qualifiedName — soft-null DEP+coerce then empty → ValueError (php-src sxe.c / #31554).
        $name = VmString::stringBuiltinArgForFrame(
            $frame,
            1,
            'SimpleXMLElement::addAttribute',
            0,
            'qualifiedName',
            false
        );
        $valueVar = $frame->calledArgs[2]->resolveIndirect();
        if (Variable::TYPE_STRING !== $valueVar->type) {
            throw new \TypeError('SimpleXMLElement::addAttribute(): Argument #2 ($value) must be of type string');
        }
        $namespace = null;
        if (\count($frame->calledArgs) >= 4) {
            $nsVar = $frame->calledArgs[3]->resolveIndirect();
            if (Variable::TYPE_NULL !== $nsVar->type) {
                if (Variable::TYPE_STRING !== $nsVar->type) {
                    throw new \TypeError('SimpleXMLElement::addAttribute(): Argument #3 ($namespace) must be of type ?string');
                }
                $namespace = $nsVar->toString();
            }
        }
        VmSimpleXml::addAttribute(
            $frame->vmContext,
            $entry,
            $name,
            $valueVar->toString(),
            $namespace,
            $frame
        );
        if (null !== $frame->returnVar) {
            $frame->returnVar->null();
        }
    }
}
