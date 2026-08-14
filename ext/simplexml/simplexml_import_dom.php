<?php

declare(strict_types=1);

namespace PHPCompiler\ext\simplexml;

use PHPCompiler\ext\dom\VmDom;
use PHPCompiler\ext\dom\VmDomSimpleXmlBridge;
use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** simplexml_import_dom() — DOMNode to SimpleXMLElement bridge (php-src ext/simplexml/simplexml.c; #6057, #20291). */
final class simplexml_import_dom extends Internal
{
    public function __construct()
    {
        parent::__construct('simplexml_import_dom');
    }

    public function execute(Frame $frame): void
    {
        // php-src simplexml.stub.php: simplexml_import_dom(object $node, ?string $class = SimpleXMLElement::class) (#30828).
        $this->requireArgCountRange($frame, 'simplexml_import_dom', 1, 2);
        if (null === $frame->vmContext) {
            throw new \LogicException('simplexml_import_dom() requires VM context');
        }

        $nodeVar = $frame->calledArgs[0]->resolveIndirect();
        // Zend typed arg: non-objects report "object"; wrong objects report the union (#20291).
        if (Variable::TYPE_OBJECT !== $nodeVar->type) {
            throw new \TypeError(sprintf(
                'simplexml_import_dom(): Argument #1 ($node) must be of type object, %s given',
                EnumCaseSupport::typeNameForVariable($nodeVar)
            ));
        }
        $node = $nodeVar->toObject();
        $isSxe = VmDomSimpleXmlBridge::isSimpleXmlElementInstance($node, $frame->vmContext);
        $isDom = VmDom::isDomNodeInstance($node, $frame->vmContext);
        if (!$isSxe && !$isDom) {
            throw new \TypeError(sprintf(
                'simplexml_import_dom(): Argument #1 ($node) must be of type SimpleXMLElement|DOMNode, %s given',
                EnumCaseSupport::typeNameForVariable($nodeVar)
            ));
        }

        $className = null;
        if (isset($frame->calledArgs[1])) {
            $classArg = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_NULL !== $classArg->type) {
                $className = VmString::coerceStringBuiltinArg(
                    $frame->calledArgs[1],
                    'simplexml_import_dom',
                    1,
                    'class_name'
                );
            }
        }

        $class = VmDomSimpleXmlBridge::resolveSimpleXmlClass($frame->vmContext, $className);

        if ($isSxe) {
            $imported = VmDomSimpleXmlBridge::importSimpleXmlElement($frame->vmContext, $node, $class);
        } else {
            $imported = VmDomSimpleXmlBridge::importDom($frame->vmContext, $node, $class);
        }
        if (null === $imported) {
            self::warnInvalidNodeType($frame);
            if (null !== $frame->returnVar) {
                $frame->returnVar->null();
            }

            return;
        }

        if (null !== $frame->returnVar) {
            $frame->returnVar->object($imported);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('simplexml_import_dom() is not JIT-lowered in this compiler build');
    }

    private static function warnInvalidNodeType(Frame $frame): void
    {
        if (null === $frame->vmContext) {
            return;
        }
        $frame->vmContext->errors->triggerError(
            'simplexml_import_dom(): Invalid Nodetype to import',
            ErrorReporter::E_WARNING,
            '' !== $frame->scriptPath ? $frame->scriptPath : null,
            $frame->vmContext,
            $frame
        );
    }
}
