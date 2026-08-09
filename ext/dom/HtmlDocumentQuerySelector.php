<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/**
 * Dom\Document::querySelector() — VM (php-src ext/dom/parentnode.c; #19580, #29453).
 *
 * Declared on abstract Dom\Document; inherited by HTMLDocument + XMLDocument.
 */
final class HtmlDocumentQuerySelector extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('querySelector');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->documentReceiver($frame, 'Dom\\Document::querySelector()');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('Dom\\Document::querySelector() expects exactly 1 argument, 0 given');
        }
        $selectors = $this->stringArg($frame->calledArgs[1], 'Dom\\Document::querySelector()', 0, 'selectors');
        $found = VmDomLiving::querySelector($receiver, $selectors);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($found): void {
            if (null === $found) {
                $ret->null();
            } else {
                $ret->object($found);
            }
        });
    }

    private function documentReceiver(Frame $frame, string $label): ObjectEntry
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
        if (!VmDomLiving::isLivingDocument($object)) {
            throw new \TypeError($label.' must be called on a Dom\\Document instance');
        }

        return $object;
    }

    private function stringArg(Variable $var, string $label, int $index, string $paramName): string
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_STRING !== $var->type && Variable::TYPE_NULL !== $var->type) {
            throw new \TypeError(sprintf(
                '%s: Argument #%d ($%s) must be of type string, %s given',
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
