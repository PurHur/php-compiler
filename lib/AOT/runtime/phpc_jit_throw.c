#include <stdint.h>
#include <string.h>

/*
 * Pending thrown object for JIT try/catch (issues #57, #195, #1056, #2157).
 *
 * Native JIT sets the object and branches to catch dispatch; uncaught throws
 * propagate to PHP via Func\JIT::execute after the compiled function returns.
 */

typedef struct {
    int32_t refcount;
    int32_t typeinfo;
} __ref__;

typedef struct {
    __ref__ ref;
    int64_t class_id;
    int8_t constructed;
} __object__;

static __object__ *phpc_jit_throw_pending_obj;
static int phpc_jit_throw_pending_set;

#define PHPC_JIT_MAX_CLASSES 512

static struct {
    char name[96];
    int64_t class_id;
} phpc_jit_class_registry[PHPC_JIT_MAX_CLASSES];

static int phpc_jit_class_registry_len;

void phpc_jit_clear_throw_pending(void)
{
    phpc_jit_throw_pending_set = 0;
    phpc_jit_throw_pending_obj = 0;
}

void phpc_jit_register_class(const char *lc_name, int64_t class_id)
{
    if (NULL == lc_name || '\0' == *lc_name) {
        return;
    }
    for (int i = 0; i < phpc_jit_class_registry_len; ++i) {
        if (0 == strcmp(phpc_jit_class_registry[i].name, lc_name)) {
            phpc_jit_class_registry[i].class_id = class_id;

            return;
        }
    }
    if (phpc_jit_class_registry_len >= PHPC_JIT_MAX_CLASSES) {
        return;
    }
    strncpy(phpc_jit_class_registry[phpc_jit_class_registry_len].name, lc_name, sizeof(phpc_jit_class_registry[0].name) - 1);
    phpc_jit_class_registry[phpc_jit_class_registry_len].name[sizeof(phpc_jit_class_registry[0].name) - 1] = '\0';
    phpc_jit_class_registry[phpc_jit_class_registry_len].class_id = class_id;
    ++phpc_jit_class_registry_len;
}

static int64_t phpc_jit_class_id_for_lcname(const char *lc_name)
{
    if (NULL == lc_name) {
        return -1;
    }
    for (int i = 0; i < phpc_jit_class_registry_len; ++i) {
        if (0 == strcmp(phpc_jit_class_registry[i].name, lc_name)) {
            return phpc_jit_class_registry[i].class_id;
        }
    }

    return -1;
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

int phpc_jit_object_is_instance(__object__ *obj, int64_t expected_class_id)
{
    if (!obj) {
        return 0;
    }

    return obj->class_id == expected_class_id;
}

int phpc_jit_object_is_instance_lcname(__object__ *obj, const char *lc_name)
{
    int64_t expected = phpc_jit_class_id_for_lcname(lc_name);

    if (expected < 0 || !obj) {
        return 0;
    }

    return obj->class_id == expected;
}

void phpc_jit_uncaught_throw_abort(void)
{
    abort();
}
