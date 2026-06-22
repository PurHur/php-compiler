<?php

namespace PHPCompiler;

require_once __DIR__ . '/../BaseTest.php';

/** VM compliance for anonymous closures and arrow functions (issues #72, #142). */
class ClosureVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__ . '/../../bin/vm.php');
    }

    public static function providePHPTests(): \Generator
    {
        foreach (['closure_simple.phpt', 'closure_arrow.phpt', 'arrow_fn_byref.phpt', 'arrow_fn_this_outside_context.phpt', 'nested_arrow_function.phpt', 'nested_arrow_capture_no_undefined_warning.phpt', 'closure_in_array.phpt', 'closure_array_element_call.phpt', 'closure_use.phpt', 'closure_use_byref.phpt', 'closure_use_byref_mutate.phpt', 'closure_this_binding.phpt', 'closure_auto_bind_private.phpt', 'closure_array_map.phpt', 'closure_from_callable.phpt', 'closure_from_callable_method.phpt', 'closure_from_callable_inaccessible.phpt', 'closure_from_callable_enum_case.phpt', 'closure_bind_to.phpt', 'closure_bind_static.phpt', 'closure_bind_enum_case.phpt', 'closure_bindto_enum_case.phpt', 'closure_bindto_enum_scope.phpt', 'closure_bind_null_this_static.phpt', 'closure_bind_invalid_scope.phpt', 'static_arrow_fn.phpt', 'static_closure_fn.phpt', 'static_closure_bind.phpt', 'static_call_unbound_closure.phpt', 'closure_static_var.phpt', 'static_var_closure_init_fatal.phpt', 'static_var_arrow_init_fatal.phpt', 'fcc_default_parameter_compile_error.phpt', 'fcc_instance_method.phpt', 'fcc_instance_expr.phpt', 'fcc_instance_method_error.phpt', 'fcc_new_instance_expr.phpt'] as $file) {
            $path = __DIR__ . '/cases/language/' . $file;
            $name = preg_replace('/\.phpt$/', '', $file) ?: $file;
            yield $name => self::parsePHPT($path, $file);
        }
    }
}
