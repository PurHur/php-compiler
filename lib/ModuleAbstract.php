<?php

/*
 * This file is part of PHP-Compiler, a PHP CFG Compiler for PHP code
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

namespace PHPCompiler;
use PHPCfg\Script;
use PHPCfg\Func as CfgFunc;

abstract class ModuleAbstract implements Module {
    protected Runtime $runtime;

    public function getName(): string {
        return str_replace('\\', '_', get_class($this));
    }

    /** php-src zend_module_entry name (e.g. ext/hash/Module → hash). */
    public function getExtensionName(): string
    {
        $class = static::class;
        if (preg_match('#\\\\ext\\\\([^\\\\]+)\\\\Module$#', $class, $matches)) {
            return $matches[1];
        }

        return strtolower(preg_replace('#.*\\\\([^\\\\]+)$#', '$1', $class));
    }

    /**
     * php-src zend_module_entry version — reported by phpversion($extension).
     *
     * Bundled PHP extensions track the active reported PHP version (reference
     * profile {@see CompilerVersion::REFERENCE_PHP_VERSION}), not the forward
     * {@see CompilerVersion::VERSION} string (#25819, ext/standard/info.c).
     */
    public function getExtensionVersion(): string
    {
        return CompilerVersion::reportedPhpVersion();
    }

    /**
     * Logical extensions bundled with this module (e.g. json/date handlers in standard).
     *
     * @return list<string>
     */
    public function getAdditionalExtensionNames(): array
    {
        return [];
    }

    /**
     * Declared depends[] from ext/<name>/ext.json (#36204).
     *
     * Identity is the ext/ directory, not {@see getExtensionName()} (20 modules report
     * {@code standard}). Overrides on Module.php are removed once manifests are SSOT.
     *
     * @return list<string>
     */
    public function getExtensionDependencies(): array
    {
        return ExtensionRegistry::dependenciesFor($this->extensionDirectoryName());
    }

    /**
     * default_enabled from ext/<name>/ext.json (#36204).
     *
     * An extension that should be opt-in sets {@code "default_enabled": false} in its manifest.
     * Nothing selects on it yet beyond {@see ExtensionRegistry} `--only` / `--without` generation.
     */
    public function isDefaultEnabled(): bool
    {
        return ExtensionRegistry::isDefaultEnabledFor($this->extensionDirectoryName());
    }

    /**
     * ext/ directory name for this Module class — unique even when getExtensionName() is 'standard'.
     */
    private function extensionDirectoryName(): string
    {
        $class = static::class;
        if (preg_match('#\\\\ext\\\\([^\\\\]+)\\\\Module$#', $class, $matches)) {
            return $matches[1];
        }

        return strtolower(preg_replace('#.*\\\\([^\\\\]+)$#', '$1', $class));
    }

    /**
     * @return array<string, string>
     */
    public function getAdditionalExtensionVersions(): array
    {
        return [];
    }

    public function getFunctions(): array {
        return [];
    }

    public function init(Runtime $runtime): void {
        $this->runtime = $runtime;
    }

    public function shutdown(): void {
        
    }

    public function jitInit(JIT\Context $context): void {
        
    }

    public function jitShutdown(JIT\Context $context): void {

    }

    protected function parseAndCompileFunction(string $name, string $filename): Func {
        $script = $this->runtime->parse(file_get_contents($filename), $filename);
        $func = $this->findFunction($name, $script);
        return $this->runtime->compileFunc($name, $func);
    }

    protected function findFunction(string $name, Script $script): CfgFunc {
        foreach ($script->functions as $func) {
            $parts = explode('\\', $func->name);
            if ($func->name === $name) {
                return $func;
            } elseif (end($parts) === $name) {
                return $func;
            }
        }
        throw new \LogicException('Could not find function named ' . $name);
    }

}