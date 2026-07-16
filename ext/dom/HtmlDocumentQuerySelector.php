<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/** Dom\HTMLDocument::querySelector() — VM (php-src ext/dom/parentnode.c; #19580). */
final class HtmlDocumentQuerySelector extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('querySelector');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->htmlDocumentReceiver($frame, 'Dom\\HTMLDocument::querySelector()');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('Dom\\HTMLDocument::querySelector() expects exactly 1 argument, 0 given');
        }
        $selectors = $this->stringArg($frame->calledArgs[1], 'Dom\\HTMLDocument::querySelector()', 0, 'selectors');
        $found = VmDomLiving::querySelector($receiver, $selectors);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($found): void {
            if (null === $found) {
                $ret->null();
            } else {
                $ret->object($found);
            }
        });
    }

    private function htmlDocumentReceiver(Frame $frame, string $label): ObjectEntry
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException($label.' called without $this');
        }
        $var = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $var->type) {
            throw new \TypeError(sprintf(
                '%s must be called on an object, %s given',
                $label,
                VmDom::typeLabel($var)
            ));
        }
        $object = $var->toObject();
        if (VmDomLiving::CLASS_HTML_DOCUMENT !== strtolower($object->class->name)) {
            throw new \TypeError($label.' must be called on a Dom\\HTMLDocument instance');
        }

        return $object;
    }

    private function stringArg(Variable $var, string $label, int $index, string $paramName): string
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_STRING !== $var->type && Variable::TYPE_NULL !== $var->type) {
            throw new \TypeError(sprintf(
                '%s expects argument #%d ($%s) to be of type string, %s given',
                $label,
                $index + 1,
                $paramName,
                VmDom::typeLabel($var)
            ));
        }
        if (Variable::TYPE_NULL === $var->type) {
            return '';
        }

        return $var->toString();
    }
}
