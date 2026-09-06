<?php

declare(strict_types=1);

namespace PHPCompiler\Compiler\Concern;

use PHPCfg\Op;

/**
 * Reserved class / const name rejects (#36387).
 *
 * Extracted from {@see ClassLikeAndStmtCompile} so gen-0 split-TU can hollow a smaller Concern TU.
 * Call sites and visibility stay identical so LintCompiler overrides are unaffected.
 * Mirrors php-src Zend/zend_compile.c {@code reserved_class_names} /
 * {@code zend_is_reserved_class_name()} / {@code zend_compile_const_decl()} — move-only.
 */
trait CompileReservedNames
{
    /**
     * php-src Zend/zend_compile.c {@code reserved_class_names} / {@code zend_is_reserved_class_name()} (#32206).
     * Match is case-insensitive on the unqualified name; {@code parent}/{@code self}/{@code static}
     * and {@code array}/{@code callable} are in the C table (usually parse errors as identifiers).
     *
     * @var array<string, true>
     */
    private const RESERVED_CLASS_NAMES = [
        'bool' => true,
        'false' => true,
        'float' => true,
        'int' => true,
        'null' => true,
        'parent' => true,
        'self' => true,
        'static' => true,
        'string' => true,
        'true' => true,
        'void' => true,
        'never' => true,
        'iterable' => true,
        'object' => true,
        'mixed' => true,
        'array' => true,
        'callable' => true,
    ];

    /**
     * php-src zend_compile_const_decl() + zend_get_special_const() (#32228).
     * File-scope `const true` / `false` / `null` (any case, any namespace prefix) is a
     * compile fatal. Message preserves the source spelling of the unqualified name.
     * `define('true', 1)` stays a runtime warning — do not call this from the define() path.
     */
    protected function rejectReservedGlobalConstName(Op\Terminal\Const_ $const): void
    {
        $name = $this->staticNameFromOperand($const->name);
        if (null === $name || '' === $name) {
            return;
        }
        $unqualified = $name;
        if (str_contains($name, '\\')) {
            $parts = explode('\\', $name);
            $unqualified = $parts[count($parts) - 1];
        }
        $lc = strtolower($unqualified);
        if ('true' !== $lc && 'false' !== $lc && 'null' !== $lc) {
            return;
        }
        $detail = sprintf("Cannot redeclare constant '%s'", $unqualified);
        $sourceFile = $const->getFile();
        if ('' === $sourceFile) {
            $sourceFile = 'unknown';
        }
        $this->throwCompileError($detail, $sourceFile, $const->getLine());
    }

    /**
     * php-src zend_compile_class_const_declaration() + zend_check_const_and_trait_alias_name() (#32251).
     * Declared class/interface/trait/enum constant named `class` (any case) is a compile fatal;
     * `Foo::class` the pseudo-constant is a fetch, not a declaration.
     */
    protected function rejectReservedClassConstName(?string $constName, Op\Terminal\Const_ $const): void
    {
        if (null === $constName || '' === $constName) {
            return;
        }
        $unqualified = $constName;
        if (str_contains($constName, '\\')) {
            $parts = explode('\\', $constName);
            $unqualified = $parts[count($parts) - 1];
        }
        if ('class' !== strtolower($unqualified)) {
            return;
        }
        $detail = "A class constant must not be called 'class'; it is reserved for class name fetching";
        $sourceFile = $const->getFile();
        if ('' === $sourceFile) {
            $sourceFile = 'unknown';
        }
        $this->throwCompileError($detail, $sourceFile, $const->getLine());
    }

    /**
     * php-src zend_assert_valid_class_name() — compile fatal before TYPE_DECLARE_* (#32206).
     * Message shape is PHP 8.2/8.3: Cannot use '%s' as class name as it is reserved.
     */
    protected function assertNotReservedClassName(string $name, Op $op): void
    {
        $unqualified = $name;
        if (str_contains($name, '\\')) {
            $parts = explode('\\', $name);
            $unqualified = $parts[count($parts) - 1];
        }
        if (!isset(self::RESERVED_CLASS_NAMES[strtolower($unqualified)])) {
            return;
        }
        $detail = sprintf("Cannot use '%s' as class name as it is reserved", $unqualified);
        $sourceFile = $op->getFile();
        if ('' === $sourceFile) {
            $sourceFile = 'unknown';
        }
        $this->throwCompileError($detail, $sourceFile, $op->getLine());
    }
}
