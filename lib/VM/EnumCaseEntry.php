<?php

namespace PHPCompiler\VM;

use PHPCompiler\Frame;

/**
 * Zend-style enum case object for E::Case fetches (#3420, #3554, #3114).
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

    public function fetchProperty(
        string $name,
        ?Context $context = null,
        ?Frame $frame = null
    ): Variable {
        EnumSupport::ensureBackedEnumValuesUnique(
            EnumSupport::resolveRuntimeEnumClass($context, $this->enumClass)
        );
        $lc = strtolower($name);
        if ('name' === $lc) {
            $var = new Variable(Variable::TYPE_STRING);
            $var->string($this->caseName);

            return $var;
        }
        if ('value' === $lc) {
            if (null === $this->enumClass->backedType) {
                // Unit enums: null without warning (#5731, zend_enum.c).
                $var = new Variable();
                $var->null();

                return $var;
            }
            $var = new Variable();
            $var->copyFrom($this->backingValue);

            return $var;
        }
        EnumCaseSupport::warnUndefinedEnumProperty($this->enumClass, $name, $context, $frame);
        $var = new Variable();
        $var->null();

        return $var;
    }
}
