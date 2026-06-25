<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Zend pseudo-extension Core builtin names (php-src Zend/zend_API.c, ext/standard/info.c).
 *
 * Used by get_extension_funcs('Core'), extension_loaded('Core'), and ReflectionFunction::getExtensionName() (#11461, #6678).
 */
final class CoreExtensionFunctions
{
    /** @var list<string> lowercase names — snapshot from Zend PHP 8.2 built-in Core module */
    public const FUNCTIONS = [
        'class_alias',
        'class_exists',
        'debug_backtrace',
        'debug_print_backtrace',
        'define',
        'defined',
        'enum_exists',
        'error_reporting',
        'extension_loaded',
        'func_get_arg',
        'func_get_args',
        'func_num_args',
        'function_exists',
        'gc_collect_cycles',
        'gc_disable',
        'gc_enable',
        'gc_enabled',
        'gc_mem_caches',
        'gc_status',
        'get_called_class',
        'get_class',
        'get_class_methods',
        'get_class_vars',
        'get_declared_classes',
        'get_declared_interfaces',
        'get_declared_traits',
        'get_defined_constants',
        'get_defined_functions',
        'get_defined_vars',
        'get_extension_funcs',
        'get_included_files',
        'get_loaded_extensions',
        'get_mangled_object_vars',
        'get_object_vars',
        'get_parent_class',
        'get_required_files',
        'get_resource_id',
        'get_resource_type',
        'get_resources',
        'interface_exists',
        'is_a',
        'is_subclass_of',
        'method_exists',
        'property_exists',
        'restore_error_handler',
        'restore_exception_handler',
        'set_error_handler',
        'set_exception_handler',
        'strcasecmp',
        'strcmp',
        'strlen',
        'strncasecmp',
        'strncmp',
        'trait_exists',
        'trigger_error',
        'user_error',
        'zend_version',
    ];

    public static function isCoreFunction(string $functionName): bool
    {
        return \in_array(strtolower($functionName), self::FUNCTIONS, true);
    }
}
