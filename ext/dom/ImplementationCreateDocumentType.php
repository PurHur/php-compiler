<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;

/** DOMImplementation::createDocumentType() — VM (#6140). */
final class ImplementationCreateDocumentType extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('createDocumentType');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 4) {
            throw new \LogicException('DOMImplementation::createDocumentType() expects exactly 3 arguments');
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('DOMImplementation::createDocumentType() requires VM context');
        }
        self::receiver($frame, VmDom::CLASS_IMPLEMENTATION, 'DOMImplementation::createDocumentType()');
        $qualifiedName = $this->stringArg($frame->calledArgs[1], 'DOMImplementation::createDocumentType()', 0);
        $publicId = $this->stringArg($frame->calledArgs[2], 'DOMImplementation::createDocumentType()', 1);
        $systemId = $this->stringArg($frame->calledArgs[3], 'DOMImplementation::createDocumentType()', 2);
        if (null !== $frame->returnVar) {
            $frame->returnVar->copyFrom(VmDom::createDocumentType(
                $frame->vmContext,
                $qualifiedName,
                $publicId,
                $systemId
            ));
        }
    }
}
