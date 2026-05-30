<?php

namespace PHPCompiler\VM;

/**
 * Zend-style enum case object for E::Case fetches (#3420, #3554).
 */
final class EnumCaseEntry
{
    public ClassEntry $enumClass;
    public string $caseName;
    public Variable $backingValue;

    public function __construct(ClassEntry $enumClass, string $caseName, Variable $backingValue)
    {
        $this->enumClass = $enumClass;
        $this->caseName = $caseName;
        $this->backingValue = $backingValue;
    }
}
