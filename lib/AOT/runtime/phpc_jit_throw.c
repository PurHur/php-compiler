/*
 * Pending thrown object for JIT try/catch (issues #57, #195, #1056).
 *
 * Native JIT sets the object and branches to catch dispatch; uncaught throws
 * propagate to PHP via Func\JIT::execute after the compiled function returns.
 */

typedef struct __object__ __object__;

static __object__ *phpc_jit_throw_pending_obj;
static int phpc_jit_throw_pending_set;

void phpc_jit_clear_throw_pending(void)
{
    phpc_jit_throw_pending_set = 0;
    phpc_jit_throw_pending_obj = 0;
}

int phpc_jit_has_throw_pending(void)
{
    return phpc_jit_throw_pending_set;
}

void phpc_jit_set_throw_pending(__object__ *obj)
{
    phpc_jit_throw_pending_obj = obj;
    phpc_jit_throw_pending_set = 1;
}

__object__ *phpc_jit_take_throw_pending(void)
{
    __object__ *obj = phpc_jit_throw_pending_obj;
    phpc_jit_throw_pending_set = 0;
    phpc_jit_throw_pending_obj = 0;

    return obj;
}
