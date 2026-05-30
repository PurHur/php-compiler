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

    public function fetchProperty(string $name): Variable
    {
        $lc = strtolower($name);
        if ('name' === $lc) {
            $var = new Variable(Variable::TYPE_STRING);
            $var->string($this->caseName);

            return $var;
        }
        if ('value' === $lc) {
            if (null === $this->enumClass->backedType) {
                throw new \LogicException(
                    'Attempt to read property "value" on unit enum case '.$this->enumClass->name.'::'.$this->caseName
                );
            }
            $var = new Variable();
            $var->copyFrom($this->backingValue);

            return $var;
        }
        throw new \LogicException(
            'Undefined property: '.$this->enumClass->name.'::$'.$name
        );
    }
}
