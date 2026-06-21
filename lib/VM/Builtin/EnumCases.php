<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\EnumSupport;

/** Synthetic Enum::cases() — Zend zend_enum_list_cases parity (#3308). */
final class EnumCases extends VmClassMethod
{
    private ClassEntry $enumClass;

    public function __construct(ClassEntry $enumClass)
    {
        parent::__construct('cases');
        $this->enumClass = $enumClass;
    }

    public function execute(Frame $frame): void
    {
        if (null !== $frame->returnVar) {
            $frame->returnVar->copyFrom(
                EnumSupport::casesList($this->enumClass, $frame->vmContext)
            );
        }
    }
}
