<?php

declare(strict_types=1);

namespace PHPCompiler\ext\tidy;

use PHPCompiler\ModuleAbstract;
use PHPCompiler\Runtime;

/**
 * tidy extension module entry (php-src ext/tidy/tidy.c; #21464 / #3664).
 *
 * Register tidy_parse_string / tidy_repair_* + tidy::cleanRepair/repair*; host bridge when present.
 */
class Module extends ModuleAbstract
{
    public function getExtensionVersion(): string
    {
        return '8.2.0';
    }

    public function init(Runtime $runtime): void
    {
        require_once __DIR__.'/BuiltinClasses.php';
        require_once __DIR__.'/VmTidy.php';
        require_once __DIR__.'/tidy_parse_string.php';
        require_once __DIR__.'/tidy_parse_file.php';
        require_once __DIR__.'/tidy_repair_string.php';
        require_once __DIR__.'/tidy_repair_file.php';
        require_once __DIR__.'/tidy_clean_repair.php';
        require_once __DIR__.'/tidy_get_output.php';
        require_once __DIR__.'/tidy_diagnose.php';
        require_once __DIR__.'/tidy_get_error_buffer.php';
        require_once __DIR__.'/tidy_getopt.php';
        require_once __DIR__.'/tidy_get_config.php';
        require_once __DIR__.'/tidy_get_status.php';
        parent::init($runtime);
        if (!TidyExtensionPolicy::advertisesExtension()) {
            return;
        }
        BuiltinClasses::register($runtime->vmContext);
    }

    public function getExtensionName(): string
    {
        return TidyExtensionPolicy::advertisesExtension() ? 'tidy' : 'standard';
    }

    public function getFunctions(): array
    {
        if (!TidyExtensionPolicy::advertisesExtension()) {
            return [];
        }

        return [
            new tidy_parse_string(),
            new tidy_parse_file(),
            new tidy_repair_string(),
            new tidy_repair_file(),
            new tidy_clean_repair(),
            new tidy_get_output(),
            new tidy_diagnose(),
            new tidy_get_error_buffer(),
            new tidy_getopt(),
            new tidy_get_config(),
            new tidy_get_status(),
        ];
    }
}
