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
     * Extensions that must be loaded before this one (RELEASE-PLAN Phase 2.5).
     *
     * Today the load order is a hand-maintained list in Runtime::loadCoreModules(), where the
     * ordering constraints are real but implicit — libxml before dom, dom before xsl/simplexml.
     * Declaring them here lets the order be derived and checked instead of remembered.
     *
     * Names are extension names as returned by getExtensionName() (e.g. 'libxml'), lowercase.
     *
     * @return list<string>
     */
    public function getExtensionDependencies(): array;

    /**
     * Is this extension part of the default build set?
     *
     * Every extension returns true today, which is exactly the current behaviour: all 76 are loaded
     * unconditionally and a script that never touches an extension still pays for it. This is the
     * declaration a per-build extension set will select on; nothing consumes it for that yet.
     */
    public function isDefaultEnabled(): bool;

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
