<?php

declare(strict_types=1);

namespace PHPCompiler\ext\igbinary;

use PHPCompiler\ModuleAbstract;

/**
 * igbinary extension module entry (php-src ext/igbinary/igbinary.c; #6573, #7033).
 *
 * Register under {@see standard}; advertise logical {@code igbinary} extension when
 * {@see IgbinaryExtensionPolicy::advertisesExtension()} — withheld on reference profile.
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
        if (!IgbinaryExtensionPolicy::advertisesExtension()) {
            return [];
        }

        return ['igbinary'];
    }

    public function getFunctions(): array
    {
        if (!IgbinaryExtensionPolicy::advertisesExtension()) {
            return [];
        }

        return [
            new igbinary_serialize(),
            new igbinary_unserialize(),
            new igbinary_pack(),
            new igbinary_unpack(),
        ];
    }
}
