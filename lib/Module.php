<?php

declare(strict_types=1);

/**
 * This file is part of PHP-Compiler, a PHP CFG Compiler for PHP code
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

namespace PHPCompiler;

interface Module
{
    public function getName(): string;

    public function getExtensionName(): string;

    /** php-src zend_module_entry version — reported by phpversion($extension). */
    public function getExtensionVersion(): string;

    /**
     * @return list<string>
     */
    public function getAdditionalExtensionNames(): array;

    /**
     * Logical extension versions bundled with this module (e.g. pcre in standard).
     *
     * @return array<string, string> lowercase extension name => version
     */
    public function getAdditionalExtensionVersions(): array;

    public function getFunctions(): array;

    public function init(Runtime $runtime): void;

    public function jitInit(JIT\Context $context): void;

    public function jitShutdown(JIT\Context $context): void;

    public function shutdown(): void;
}
