<?php

declare(strict_types=1);

namespace PHPCompiler\ext\tidy;

use PHPCompiler\ModuleAbstract;
use PHPCompiler\Runtime;
use PHPCompiler\VM\Variable;

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
        require_once __DIR__.'/tidy_get_opt_doc.php';
        require_once __DIR__.'/tidy_get_config.php';
        require_once __DIR__.'/tidy_get_status.php';
        require_once __DIR__.'/tidy_error_count.php';
        require_once __DIR__.'/tidy_warning_count.php';
        require_once __DIR__.'/tidy_access_count.php';
        require_once __DIR__.'/tidy_config_count.php';
        require_once __DIR__.'/tidy_get_release.php';
        require_once __DIR__.'/tidy_get_html_ver.php';
        require_once __DIR__.'/tidy_is_xhtml.php';
        require_once __DIR__.'/tidy_is_xml.php';
        require_once __DIR__.'/tidy_get_root.php';
        require_once __DIR__.'/tidy_get_html.php';
        require_once __DIR__.'/tidy_get_head.php';
        require_once __DIR__.'/tidy_get_body.php';
        parent::init($runtime);
        if (!TidyExtensionPolicy::advertisesExtension()) {
            return;
        }
        foreach (TidyConstants::registeredConstants() as $name => $value) {
            $var = new Variable();
            $var->int($value);
            $runtime->vmContext->defineConstant($name, $var);
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
            new tidy_get_opt_doc(),
            new tidy_get_config(),
            new tidy_get_status(),
            new tidy_error_count(),
            new tidy_warning_count(),
            new tidy_access_count(),
            new tidy_config_count(),
            new tidy_get_release(),
            new tidy_get_html_ver(),
            new tidy_is_xhtml(),
            new tidy_is_xml(),
            new tidy_get_root(),
            new tidy_get_html(),
            new tidy_get_head(),
            new tidy_get_body(),
        ];
    }
}
