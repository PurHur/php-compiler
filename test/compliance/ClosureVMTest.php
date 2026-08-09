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
        foreach (['closure_simple.phpt', 'closure_arrow.phpt', 'arrow_fn_byref.phpt', 'arrow_fn_this_outside_context.phpt', 'nested_arrow_function.phpt', 'nested_arrow_capture_no_undefined_warning.phpt', 'closure_in_array.phpt', 'closure_array_element_call.phpt', 'closure_use.phpt', 'closure_use_byref.phpt', 'closure_use_byref_mutate.phpt', 'closure_recursive_use_byref.phpt', 'closure_this_binding.phpt', 'closure_auto_bind_private.phpt', 'closure_parent_method_private_scope.phpt', 'closure_array_map.phpt', 'closure_from_callable.phpt', 'closure_from_callable_method.phpt', 'closure_from_callable_this_private_invoke.phpt', 'closure_from_callable_class_in_closure_private.phpt', 'closure_from_callable_inaccessible.phpt', 'closure_from_callable_static_private_binds_this.phpt', 'closure_fromcallable_static_scope_typeerror.phpt', 'closure_from_callable_enum_case.phpt', 'closure_bind_to.phpt', 'closure_bind_static.phpt', 'closure_bind_inline_new_private.phpt', 'closure_bind_instance_inline_new.phpt', 'closure_bindto_inline_new_object.phpt', 'closure_bind_enum_case.phpt', 'closure_bindto_enum_case.phpt', 'closure_bindto_enum_scope.phpt', 'closure_bindto_null_class_static.phpt', 'closure_bind_null_this_static.phpt', 'closure_bind_invalid_scope.phpt', 'closure_bindto_illegal_returns_null.phpt', 'closure_bindto_null_free_uses_this.phpt', 'static_arrow_fn.phpt', 'static_closure_fn.phpt', 'static_closure_bind.phpt', 'closure_call_static_warns.phpt', 'static_call_unbound_closure.phpt', 'closure_static_var.phpt', 'closure_static_counter.phpt', 'closure_static_use_byref.phpt', 'static_var_closure_init_fatal.phpt', 'static_var_arrow_init_fatal.phpt', 'inline_static_closure_call_arg.phpt', 'closure_call_method_rebind.phpt', 'fcc_default_parameter_compile_error.phpt', 'fcc_instance_method.phpt', 'fcc_instance_expr.phpt', 'fcc_instance_method_error.phpt', 'fcc_new_instance_expr.phpt', 'first_class_callable_parent_method.phpt', 'first_class_callable_parent_method_args.phpt', 'first_class_callable_parent_static.phpt', 'first_class_callable_self_static_lsb.phpt'] as $file) {
            $path = __DIR__ . '/cases/language/' . $file;
            $name = preg_replace('/\.phpt$/', '', $file) ?: $file;
            yield $name => self::parsePHPT($path, $file);
        }
    }
}
