<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/** Dom\HTMLDocument::saveHtml() — VM (php-src ext/dom/html_document.c; #19580). */
final class HtmlDocumentSaveHtml extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('saveHtml');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->htmlDocumentReceiver($frame, 'Dom\\HTMLDocument::saveHtml()');
        $node = null;
        if (isset($frame->calledArgs[1])) {
            $first = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_NULL !== $first->type) {
                if (Variable::TYPE_OBJECT !== $first->type || !VmDom::isDomNode($first->toObject())) {
                    throw new \TypeError('Dom\\HTMLDocument::saveHtml(): Argument #1 ($node) must be of type ?Dom\\Node');
                }
                $node = $first->toObject();
            }
        }
        $html = VmDomLiving::saveHtml($receiver, $node);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($html): void {
            $ret->string($html);
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
}
