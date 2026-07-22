<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xsl;

use PHPCompiler\Frame;
use PHPCompiler\VM\Variable;
use PHPCompiler\ext\dom\VmDom;

/**
 * XSLTProcessor::registerPHPFunctionNS() — namespaced XSLT PHP callbacks (#22243).
 *
 * php-src: ext/xsl/xsltprocessor.c — PHP_METHOD(XSLTProcessor, registerPHPFunctionNS) /
 * ext/dom/xpath_callbacks.c — php_dom_xpath_callbacks_update_single_method_handler
 */
final class XsltProcessorRegisterPhpFunctionNS extends XsltClassMethod
{
    public function __construct()
    {
        parent::__construct('registerPHPFunctionNS');
    }

    public function execute(Frame $frame): void
    {
        $entry = $this->receiver($frame, 'XSLTProcessor::registerPHPFunctionNS()');
        if (\count($frame->calledArgs) < 4) {
            throw new \ArgumentCountError(sprintf(
                'XSLTProcessor::registerPHPFunctionNS() expects exactly 3 arguments, %d given',
                max(0, \count($frame->calledArgs) - 1)
            ));
        }
        if (\count($frame->calledArgs) > 4) {
            throw new \ArgumentCountError(sprintf(
                'XSLTProcessor::registerPHPFunctionNS() expects exactly 3 arguments, %d given',
                \count($frame->calledArgs) - 1
            ));
        }
        $namespaceUri = $this->pathStringArg(
            $frame->calledArgs[1],
            'XSLTProcessor::registerPHPFunctionNS()',
            0,
            'namespaceURI'
        );
        $name = $this->pathStringArg(
            $frame->calledArgs[2],
            'XSLTProcessor::registerPHPFunctionNS()',
            1,
            'name'
        );
        $callable = $frame->calledArgs[3]->resolveIndirect();
        $ctx = $frame->vmContext ?? throw new \LogicException(
            'XSLTProcessor::registerPHPFunctionNS() requires VM context'
        );
        VmXsl::registerPHPFunctionNS($ctx, $entry, $namespaceUri, $name, $callable);
    }

    private function pathStringArg(Variable $var, string $label, int $index, string $paramName): string
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_STRING !== $var->type) {
            throw new \TypeError(sprintf(
                '%s: Argument #%d ($%s) must be of type string, %s given',
                $label,
                $index + 1,
                $paramName,
                VmDom::typeLabel($var)
            ));
        }

        return $var->toString();
    }
}
