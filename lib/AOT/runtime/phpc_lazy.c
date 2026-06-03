/*
 * Lazy object registry for JIT/AOT (PHP 8.4 ReflectionClass::newLazyGhost/Proxy).
 *
 * @see Zend/zend_lazy_objects.c
 */

#include <stddef.h>
#include <stdint.h>

#define PHPC_LAZY_MAX 4096

typedef struct {
    void *obj;
    int32_t init_index;
    int8_t pending;
    int8_t ghost;
} phpc_lazy_entry;

static phpc_lazy_entry phpc_lazy_table[PHPC_LAZY_MAX];
static int phpc_lazy_count;

static int phpc_lazy_index_of(void *obj)
{
    int i;

    for (i = 0; i < phpc_lazy_count; ++i) {
        if (phpc_lazy_table[i].obj == obj) {
            return i;
        }
    }

    return -1;
}

void phpc_lazy_register(void *obj, int32_t init_index, int ghost)
{
    int idx;

    if (NULL == obj || phpc_lazy_count >= PHPC_LAZY_MAX) {
        return;
    }
    idx = phpc_lazy_index_of(obj);
    if (idx < 0) {
        idx = phpc_lazy_count++;
        phpc_lazy_table[idx].obj = obj;
    }
    phpc_lazy_table[idx].init_index = init_index;
    phpc_lazy_table[idx].pending = 1;
    phpc_lazy_table[idx].ghost = ghost ? 1 : 0;
}

int32_t phpc_lazy_is_pending(void *obj)
{
    int idx = phpc_lazy_index_of(obj);

    return idx >= 0 ? (int32_t) phpc_lazy_table[idx].pending : 0;
}

int32_t phpc_lazy_is_ghost(void *obj)
{
    int idx = phpc_lazy_index_of(obj);

    return idx >= 0 ? (int32_t) phpc_lazy_table[idx].ghost : 0;
}

int32_t phpc_lazy_init_index(void *obj)
{
    int idx = phpc_lazy_index_of(obj);

    return idx >= 0 ? phpc_lazy_table[idx].init_index : -1;
}

void phpc_lazy_mark_done(void *obj)
{
    int idx = phpc_lazy_index_of(obj);

    if (idx >= 0) {
        phpc_lazy_table[idx].pending = 0;
    }
}

void phpc_lazy_unregister(void *obj)
{
    int idx = phpc_lazy_index_of(obj);

    if (idx < 0) {
        return;
    }
    --phpc_lazy_count;
    if (idx < phpc_lazy_count) {
        phpc_lazy_table[idx] = phpc_lazy_table[phpc_lazy_count];
    }
}
