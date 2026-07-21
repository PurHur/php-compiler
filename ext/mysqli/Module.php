<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mysqli;

use PHPCompiler\ModuleAbstract;
use PHPCompiler\Runtime;
use PHPCompiler\VM\Variable;

/**
 * ext/mysqli module entry (php-src ext/mysqli/mysqli.c; #3435).
 *
 * Register procedural mysqli_* functions + mysqli/mysqli_result classes.
 * Live connections use host ext/mysqli as bridge; without it, function_exists()
 * and class_exists() still return true but connect returns false.
 */
class Module extends ModuleAbstract
{
    public function getExtensionVersion(): string
    {
        return '8.2.0';
    }

    public function init(Runtime $runtime): void
    {
        require_once __DIR__.'/bootstrap_mysqli_sql_exception.php';
        require_once __DIR__.'/MysqliExtensionPolicy.php';
        require_once __DIR__.'/MysqliConstants.php';
        require_once __DIR__.'/MysqliClassMethod.php';
        require_once __DIR__.'/BuiltinClasses.php';
        require_once __DIR__.'/VmMysqli.php';
        require_once __DIR__.'/mysqli_connect.php';
        require_once __DIR__.'/mysqli_init.php';
        require_once __DIR__.'/mysqli_query.php';
        require_once __DIR__.'/mysqli_fetch_assoc.php';
        require_once __DIR__.'/mysqli_fetch_array.php';
        require_once __DIR__.'/mysqli_fetch_row.php';
        require_once __DIR__.'/mysqli_close.php';
        require_once __DIR__.'/mysqli_connect_errno.php';
        require_once __DIR__.'/mysqli_connect_error.php';
        require_once __DIR__.'/mysqli_free_result.php';
        require_once __DIR__.'/mysqli_real_escape_string.php';
        require_once __DIR__.'/mysqli_num_rows.php';
        require_once __DIR__.'/mysqli_affected_rows.php';
        require_once __DIR__.'/mysqli_error.php';
        require_once __DIR__.'/mysqli_errno.php';
        require_once __DIR__.'/mysqli_init.php';
        require_once __DIR__.'/MysqliReportMode.php';
        require_once __DIR__.'/mysqli_report.php';
        parent::init($runtime);
        if (!MysqliExtensionPolicy::advertisesExtension()) {
            return;
        }
        foreach (MysqliConstants::registeredConstants() as $name => $value) {
            $var = new Variable();
            $var->int($value);
            $runtime->vmContext->defineConstant($name, $var);
        }
        BuiltinClasses::register($runtime->vmContext);
    }

    public function getExtensionName(): string
    {
        return MysqliExtensionPolicy::advertisesExtension() ? 'mysqli' : 'standard';
    }

    public function getFunctions(): array
    {
        if (!MysqliExtensionPolicy::advertisesExtension()) {
            return [];
        }

        return [
            new mysqli_connect(),
            new mysqli_init(),
            new mysqli_query(),
            new mysqli_fetch_assoc(),
            new mysqli_fetch_array(),
            new mysqli_fetch_row(),
            new mysqli_close(),
            new mysqli_connect_errno(),
            new mysqli_connect_error(),
            new mysqli_free_result(),
            new mysqli_real_escape_string(),
            new mysqli_real_escape_string('mysqli_escape_string'),
            new mysqli_num_rows(),
            new mysqli_affected_rows(),
            new mysqli_error(),
            new mysqli_errno(),
            new mysqli_init(),
            new mysqli_report(),
        ];
    }
}
