<?php

declare(strict_types=1);

namespace PHPCompiler\ext\simplexml;

use PHPCompiler\ext\dom\DomRegistry;
use PHPCompiler\ext\dom\VmDom;
use PHPCompiler\ext\dom\VmDomSimpleXmlBridge;
use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** simplexml_import_dom() — DOMNode to SimpleXMLElement bridge (php-src ext/simplexml/simplexml.c; #6057). */
final class simplexml_import_dom extends Internal
{
    public function __construct()
    {
        parent::__construct('simplexml_import_dom');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \ArgumentCountError('simplexml_import_dom() expects at least 1 argument, 0 given');
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('simplexml_import_dom() requires VM context');
        }

        $nodeVar = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $nodeVar->type) {
            throw new \TypeError(sprintf(
                'simplexml_import_dom(): Argument #1 ($node) must be of type SimpleXMLElement|DOMNode, %s given',
                VmDom::typeLabel($nodeVar)
            ));
        }
        $node = $nodeVar->toObject();

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

        if (!DomRegistry::has($node)) {
            self::warnInvalidNodeType($frame);
            if (null !== $frame->returnVar) {
                $frame->returnVar->bool(false);
            }

            return;
        }

        $imported = VmDomSimpleXmlBridge::importDom($frame->vmContext, $node, $class);
        if (null === $imported) {
            self::warnInvalidNodeType($frame);
            if (null !== $frame->returnVar) {
                $frame->returnVar->bool(false);
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
