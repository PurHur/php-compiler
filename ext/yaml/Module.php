<?php

declare(strict_types=1);

namespace PHPCompiler\ext\yaml;

use PHPCompiler\ModuleAbstract;

/**
 * yaml extension module entry (PECL yaml / yaml.c; #6275).
 *
 * PHP-in-PHP YAML 1.1 subset parse/emit — no runtime/*.c growth. Advertise logical {@code yaml}
 * extension when {@see YamlExtensionPolicy::advertisesExtension()} — withheld on reference profile
 * (Zend 8.2 typically has no ext/yaml).
 */
class Module extends ModuleAbstract
{
    /** PECL yaml PHP_YAML_VERSION-style */
    private const YAML_VERSION = '2.2.3';

    public function getExtensionVersion(): string
    {
        return self::YAML_VERSION;
    }

    public function getFunctions(): array
    {
        if (!YamlExtensionPolicy::advertisesExtension()) {
            return [];
        }

        return [
            new yaml_parse(),
            new yaml_parse_file(),
            new yaml_parse_url(),
            new yaml_emit(),
            new yaml_emit_file(),
        ];
    }
}
