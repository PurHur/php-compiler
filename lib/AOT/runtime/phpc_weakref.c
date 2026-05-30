/*
 * JIT/AOT weak reference registry (issues #3282, #3667).
 *
 * @see https://github.com/php/php-src/blob/master/Zend/zend_weakrefs.c
 */

#include <stddef.h>
#include <stdint.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>

typedef void __value__;
typedef void __string__;
typedef void __hashtable__;

extern void __value__writeNull(__value__ *out);
extern void __hashtable__unsetStringKey(__hashtable__ *ht, __string__ *key);
extern __string__ *__string__init(long long len, char *data);

typedef struct phpc_wr_ref {
    void *target;
    __value__ *slot;
} phpc_wr_ref;

typedef struct phpc_wr_map {
    void *target;
    __hashtable__ *ht;
    char key[40];
} phpc_wr_map;

#define PHPC_WR_MAX_REFS 4096
#define PHPC_WR_MAX_MAPS 4096

static phpc_wr_ref phpc_wr_refs[PHPC_WR_MAX_REFS];
static int phpc_wr_ref_count;
static phpc_wr_map phpc_wr_maps[PHPC_WR_MAX_MAPS];
static int phpc_wr_map_count;

void phpc_weakref_reset(void)
{
    phpc_wr_ref_count = 0;
    phpc_wr_map_count = 0;
}

void phpc_weakref_register_ref(void *target, void *slot)
{
    if (NULL == target || NULL == slot || phpc_wr_ref_count >= PHPC_WR_MAX_REFS) {
        return;
    }
    phpc_wr_refs[phpc_wr_ref_count].target = target;
    phpc_wr_refs[phpc_wr_ref_count].slot = (__value__ *) slot;
    ++phpc_wr_ref_count;
}

static void phpc_weakref_unset_map_key(__hashtable__ *ht, const char *key)
{
    __string__ *s;
    long long len;

    if (NULL == ht || NULL == key) {
        return;
    }
    len = (long long) strlen(key);
    s = __string__init(len, (char *) key);
    if (NULL != s) {
        __hashtable__unsetStringKey(ht, s);
    }
}

void phpc_weakref_register_map(void *target, void *ht, const char *key)
{
    phpc_wr_map *entry;

    if (NULL == target || NULL == ht || NULL == key || phpc_wr_map_count >= PHPC_WR_MAX_MAPS) {
        return;
    }
    entry = &phpc_wr_maps[phpc_wr_map_count++];
    entry->target = target;
    entry->ht = (__hashtable__ *) ht;
    snprintf(entry->key, sizeof(entry->key), "%s", key);
}

void phpc_weakref_format_object_key(void *obj, char *buf, size_t buflen)
{
    if (NULL == buf || 0 == buflen) {
        return;
    }
    snprintf(buf, buflen, "o:%llx", (unsigned long long) (uintptr_t) obj);
}

enum {
    PHPC_TYPEINFO_TYPEMASK = 0xFFFFFFFC,
    PHPC_TYPEINFO_TYPE_OBJECT = 8,
};

static void phpc_weakref_clear_object_impl(void *target);

void phpc_weakref_clear_object_typed(void *target, int typeinfo)
{
    if (NULL == target) {
        return;
    }
    if ((typeinfo & PHPC_TYPEINFO_TYPEMASK) != PHPC_TYPEINFO_TYPE_OBJECT) {
        return;
    }
    phpc_weakref_clear_object_impl(target);
}

void phpc_weakref_clear_object(void *target)
{
    phpc_weakref_clear_object_impl(target);
}

static void phpc_weakref_clear_object_impl(void *target)
{
    int i;

    if (NULL == target) {
        return;
    }
    for (i = 0; i < phpc_wr_ref_count; ++i) {
        if (phpc_wr_refs[i].target == target && NULL != phpc_wr_refs[i].slot) {
            __value__writeNull(phpc_wr_refs[i].slot);
            phpc_wr_refs[i].target = NULL;
            phpc_wr_refs[i].slot = NULL;
        }
    }
    for (i = 0; i < phpc_wr_map_count; ++i) {
        if (phpc_wr_maps[i].target == target && NULL != phpc_wr_maps[i].ht) {
            phpc_weakref_unset_map_key(phpc_wr_maps[i].ht, phpc_wr_maps[i].key);
            phpc_wr_maps[i].target = NULL;
            phpc_wr_maps[i].ht = NULL;
            phpc_wr_maps[i].key[0] = '\0';
        }
    }
}
