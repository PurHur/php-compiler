<?php

declare(strict_types=1);

namespace PHPCompiler\ext\simdjson;

use PHPCompiler\ModuleAbstract;

/**
 * simdjson extension module entry (PECL awesomized/simdjson_php; #22530).
 *
 * Register under {@see standard}; advertise logical {@code simdjson} extension and
 * simdjson_decode()/simdjson_is_valid() when {@see SimdjsonExtensionPolicy::advertisesExtension()}
 * — withheld on reference profile (Zend 8.2 has no pecl-simdjson).
 */
class Module extends ModuleAbstract
{
    public function getExtensionName(): string
    {
        return 'standard';
    }

    /**
     * @return list<string>
     */
    public function getAdditionalExtensionNames(): array
    {
        if (!SimdjsonExtensionPolicy::advertisesExtension()) {
            return [];
        }

        return ['simdjson'];
    }

    public function getFunctions(): array
    {
        if (!SimdjsonExtensionPolicy::advertisesExtension()) {
            return [];
        }

        return [
            new simdjson_decode(),
            new simdjson_is_valid(),
        ];
    }
}
