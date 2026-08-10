<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\InternalStrictArg;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/** Dom\HTMLDocument::getElementById() — VM (php-src ext/dom/php_dom.stub.php; #19580). */
final class HtmlDocumentGetElementById extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getElementById');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->htmlDocumentReceiver($frame, 'Dom\\HTMLDocument::getElementById()');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('Dom\\HTMLDocument::getElementById() expects exactly 1 argument, 0 given');
        }
        $id = $this->stringArg(
            $frame->calledArgs[1],
            'Dom\\HTMLDocument::getElementById()',
            0,
            $frame,
            'elementId'
        );
        $found = VmDomLiving::getElementById($receiver, $id);
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

    private function stringArg(
        Variable $var,
        string $label,
        int $index,
        Frame $frame,
        string $paramName
    ): string {
        if (InternalStrictArg::isCallerStrict($frame)) {
            $function = str_ends_with($label, '()') ? substr($label, 0, -2) : $label;
            InternalStrictArg::rejectNullString($var, $function, $paramName, $index, $frame);
        }
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
