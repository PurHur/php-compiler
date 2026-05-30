<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\EnumCaseEntry;
use PHPCompiler\VM\Variable;

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
        $result = new Variable();
        $result->newArray();
        $ht = $result->toArray();
        foreach ($this->enumClass->enumCases as $index => $case) {
            $backing = new Variable();
            $backing->copyFrom($case['value']);
            $caseVar = new Variable(Variable::TYPE_ENUM_CASE);
            $caseVar->enumCase(new EnumCaseEntry($this->enumClass, $case['name'], $backing));
            $ht->addIndex($index, $caseVar);
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->copyFrom($result);
        }
    }
}
