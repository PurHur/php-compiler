<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\BackedEnum;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\EnumSupport;
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
        EnumSupport::ensureBackedEnumValuesUnique($this->enumClass);
        $result = new Variable();
        $result->newArray();
        $ht = $result->toArray();
        foreach ($this->enumClass->enumCases as $index => $case) {
            $canonical = BackedEnum::canonicalCaseVariable($this->enumClass, $case['name']);
            if (null !== $canonical) {
                $caseVar = new Variable();
                $caseVar->copyFrom($canonical->resolveIndirect());
                $ht->addIndex($index, $caseVar);
                continue;
            }
            $backing = new Variable();
            $backing->copyFrom($case['value']);
            $caseVar = EnumCaseSupport::createCase($this->enumClass, $case['name'], $backing);
            $ht->addIndex($index, $caseVar);
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->copyFrom($result);
        }
    }
}
