/*
 * spl_autoload_register() callback stack for JIT/AOT (issue #1776, #1492).
 */

#include <stdlib.h>
#include <string.h>

typedef int (*phpc_spl_autoload_fn_t)(const char *class_name, size_t class_name_len);

#define PHPC_SPL_AUTOLOAD_MAX 32

typedef struct {
    phpc_spl_autoload_fn_t fn;
} phpc_spl_autoload_entry;

static phpc_spl_autoload_entry phpc_spl_autoload_stack[PHPC_SPL_AUTOLOAD_MAX];
static int phpc_spl_autoload_depth = 0;

void __phpc_spl_autoload_register_apply(void *fn_opaque, int prepend)
{
    phpc_spl_autoload_fn_t fn = (phpc_spl_autoload_fn_t) fn_opaque;

    if (phpc_spl_autoload_depth >= PHPC_SPL_AUTOLOAD_MAX || NULL == fn) {
        return;
    }
    if (prepend && phpc_spl_autoload_depth > 0) {
        int i;
        for (i = phpc_spl_autoload_depth; i > 0; i--) {
            phpc_spl_autoload_stack[i] = phpc_spl_autoload_stack[i - 1];
        }
        phpc_spl_autoload_stack[0].fn = fn;
        phpc_spl_autoload_depth++;
        return;
    }
    phpc_spl_autoload_stack[phpc_spl_autoload_depth].fn = fn;
    phpc_spl_autoload_depth++;
}

int __phpc_spl_autoload_dispatch(const char *class_name, size_t class_name_len)
{
    int i;

    if (NULL == class_name || 0 == class_name_len) {
        return 0;
    }
    for (i = 0; i < phpc_spl_autoload_depth; i++) {
        if (NULL != phpc_spl_autoload_stack[i].fn
            && 0 != phpc_spl_autoload_stack[i].fn(class_name, class_name_len)) {
            return 1;
        }
    }

    return 0;
}
