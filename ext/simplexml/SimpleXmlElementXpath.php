<?php

declare(strict_types=1);

namespace PHPCompiler\ext\simplexml;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;

/** SimpleXMLElement::xpath — descendant element query (php-src ext/simplexml/sxe.c; #18038 / #31530). */
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
        // Z_PARAM_STR $expression — soft-null DEP+coerce then evaluate (php-src sxe.c / #31530).
        // Empty/invalid expression → E_WARNING + false (not TypeError).
        $expression = VmString::stringBuiltinArgForFrame(
            $frame,
            1,
            'SimpleXMLElement::xpath',
            0,
            'expression',
            false
        );
        if (null !== $frame->returnVar) {
            $result = VmSimpleXml::xpath($frame->vmContext, $entry, $expression, $frame);
            if (false === $result) {
                $frame->returnVar->bool(false);
            } else {
                $frame->returnVar->array($result);
            }
        }
    }
}
