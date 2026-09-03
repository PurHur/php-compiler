<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable as VmVariable;
use PHPLLVM\Value;

/**
 * filter extension JIT/VM surfaces needed by lib/JIT Builtin (#36204).
 *
 * Implemented in {@code ext/filter/JitFilterExtensionHooksFacade.php}; Builtin
 * Filter* files must not import {@code ext\filter}.
 */
interface FilterExtensionHooks
{
    public function validateInt(Context $context, Variable $value): Value;

    public function validateBoolean(Context $context, Variable $value): Value;

    public function validateFloat(Context $context, Variable $value): Value;

    public function validateEmail(Context $context, Variable $value): Value;

    public function validateUrl(Context $context, Variable $value): Value;

    public function validateIp(Context $context, Variable $value): Value;

    public function validateMac(Context $context, Variable $value): Value;

    public function validateDomain(Context $context, Variable $value): Value;

    public function inputTypeParamName(string $function): string;

    public function tryPhpInputFilterInt(VmVariable $var): ?int;

    /**
     * Const-fold filter_var_array() when data/definition are compile-time.
     *
     * @param HashTable|int|null $definition
     */
    public function filterVarArrayConst(
        HashTable $data,
        HashTable|int|null $definition,
        int $addEmpty
    ): ?HashTable;
}
