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
        $enum = EnumSupport::resolveRuntimeEnumClass($frame->vmContext, $this->enumClass);
        EnumSupport::ensureBackedEnumValuesUnique($enum);
        $result = new Variable();
        $result->newArray();
        $ht = $result->toArray();
        foreach (EnumSupport::enumCaseNamesInOrder($enum) as $index => $caseName) {
            $canonical = BackedEnum::canonicalCaseVariable($enum, $caseName);
            if (null !== $canonical) {
                $caseVar = new Variable();
                $caseVar->copyFrom($canonical->resolveIndirect());
                $ht->addIndex($index, $caseVar);
                continue;
            }
            $backing = new Variable(Variable::TYPE_NULL);
            $backing->null();
            if (null !== $enum->backedType) {
                $memberLc = strtolower($caseName);
                if (isset($enum->constants[$memberLc])) {
                    $backing->copyFrom(
                        BackedEnum::caseBackingScalar($enum->backedType, $enum->constants[$memberLc])
                    );
                }
            }
            $caseVar = EnumCaseSupport::createCase($enum, $caseName, $backing);
            $ht->addIndex($index, $caseVar);
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->copyFrom($result);
        }
    }
}
