<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;

/**
 * Dom\Implementation::createDocumentType() — returns Dom\DocumentType (php-src domimplementation.c; #20910).
 *
 * php-src stub: createDocumentType(string $qualifiedName, string $publicId, string $systemId)
 * (all three required — unlike legacy DOMImplementation optional trailing args).
 */
final class LivingImplementationCreateDocumentType extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('createDocumentType');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs) - 1;
        if ($argc < 3) {
            throw new \ArgumentCountError(sprintf(
                'Dom\\Implementation::createDocumentType() expects exactly 3 arguments, %d given',
                $argc
            ));
        }
        if ($argc > 3) {
            throw new \ArgumentCountError(sprintf(
                'Dom\\Implementation::createDocumentType() expects exactly 3 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('Dom\\Implementation::createDocumentType() requires VM context');
        }
        self::receiver($frame, VmDomLiving::CLASS_IMPLEMENTATION, 'Dom\\Implementation::createDocumentType()');
        $qualifiedName = $this->stringArg(
            $frame->calledArgs[1],
            'Dom\\Implementation::createDocumentType()',
            0,
            $frame,
            'qualifiedName'
        );
        $publicId = $this->stringArg(
            $frame->calledArgs[2],
            'Dom\\Implementation::createDocumentType()',
            1,
            $frame,
            'publicId'
        );
        $systemId = $this->stringArg(
            $frame->calledArgs[3],
            'Dom\\Implementation::createDocumentType()',
            2,
            $frame,
            'systemId'
        );
        if (null !== $frame->returnVar) {
            $frame->returnVar->copyFrom(VmDomLiving::createDocumentType(
                $frame->vmContext,
                $qualifiedName,
                $publicId,
                $systemId
            ));
        }
    }
}
