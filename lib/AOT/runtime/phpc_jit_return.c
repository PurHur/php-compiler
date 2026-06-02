/*
 * Pending return for JIT try/finally (issue #4246).
 *
 * When returning from a try body with finally, JIT stores the return value here,
 * runs the finally block, then resumes the LLVM return.
 */

typedef struct __value__ __value__;

static __value__ *phpc_jit_return_pending_val;
static int phpc_jit_return_pending_set;
static int phpc_jit_return_pending_void;

void phpc_jit_clear_return_pending(void)
{
    phpc_jit_return_pending_set = 0;
    phpc_jit_return_pending_void = 0;
    phpc_jit_return_pending_val = 0;
}

int phpc_jit_has_return_pending(void)
{
    return phpc_jit_return_pending_set;
}

int phpc_jit_return_pending_is_void(void)
{
    return phpc_jit_return_pending_void;
}

void phpc_jit_set_return_pending(__value__ *val, int is_void)
{
    phpc_jit_return_pending_val = val;
    phpc_jit_return_pending_void = is_void ? 1 : 0;
    phpc_jit_return_pending_set = 1;
}

__value__ *phpc_jit_take_return_pending(void)
{
    __value__ *val = phpc_jit_return_pending_val;
    phpc_jit_return_pending_set = 0;
    phpc_jit_return_pending_void = 0;
    phpc_jit_return_pending_val = 0;

    return val;
}
