/*
 * gc_collect_cycles() JIT/AOT runtime — cyclic __object__ graphs (#3160, #3113).
 *
 * @see https://github.com/php/php-src/blob/master/Zend/zend_gc.c zend_gc_collect_cycles
 */

#include <stddef.h>
#include <stdint.h>
#include <stdlib.h>
#include <string.h>

typedef void __object__;

typedef struct __value__ {
    int8_t type;
    int8_t value[8];
} __value__;

extern __object__ *__value__readObject(__value__ *v);
extern void phpc_weakref_clear_object(void *target);
extern void phpc_weakref_clear_object_typed(void *target, int32_t typeinfo);
extern void __mm__free(void *ptr);

enum {
    PHPC_TYPE_OBJECT = 5,
    PHPC_TYPEINFO_TYPEMASK = 0xFFFFFFFC,
    PHPC_TYPEINFO_TYPE_OBJECT = 8,
    PHPC_TYPEINFO_REFCOUNTED = 1,
};

typedef struct {
    int32_t refcount;
    int32_t typeinfo;
} phpc_ref_head;

typedef struct {
    phpc_ref_head ref;
    int64_t class_id;
    int8_t constructed;
} phpc_object_header;

#define PHPC_GC_MAX_OBJECTS 65536

static void *phpc_gc_objects[PHPC_GC_MAX_OBJECTS];
static int phpc_gc_prop_counts[PHPC_GC_MAX_OBJECTS];
static unsigned char phpc_gc_marked[PHPC_GC_MAX_OBJECTS];
static int phpc_gc_inbound[PHPC_GC_MAX_OBJECTS];
static unsigned char phpc_destruct_invoked[PHPC_GC_MAX_OBJECTS];
static int phpc_gc_count;
static int phpc_gc_enabled = 1;
/** 0 = defer refcount-zero destroy until {@see phpc_gc_run_shutdown_destructors} (#4013). */
static int phpc_destruct_allow_delref = 1;

extern void __object__invoke_destructor(void *obj);

void phpc_destruct_set_allow_delref(int allow)
{
    phpc_destruct_allow_delref = allow ? 1 : 0;
}

int phpc_destruct_delref_allowed(void)
{
    return phpc_destruct_allow_delref;
}

static int phpc_gc_index_of(void *obj)
{
    int i;

    for (i = 0; i < phpc_gc_count; ++i) {
        if (phpc_gc_objects[i] == obj) {
            return i;
        }
    }

    return -1;
}

void phpc_gc_register(void *obj, int prop_count)
{
    if (NULL == obj || phpc_gc_count >= PHPC_GC_MAX_OBJECTS) {
        return;
    }
    if (phpc_gc_index_of(obj) >= 0) {
        return;
    }
    phpc_gc_objects[phpc_gc_count] = obj;
    phpc_gc_prop_counts[phpc_gc_count] = prop_count > 0 ? prop_count : 0;
    phpc_destruct_invoked[phpc_gc_count] = 0;
    ++phpc_gc_count;
}

void phpc_gc_unregister(void *obj)
{
    int idx = phpc_gc_index_of(obj);

    if (idx < 0) {
        return;
    }
    --phpc_gc_count;
    if (idx < phpc_gc_count) {
        phpc_gc_objects[idx] = phpc_gc_objects[phpc_gc_count];
        phpc_gc_prop_counts[idx] = phpc_gc_prop_counts[phpc_gc_count];
        phpc_destruct_invoked[idx] = phpc_destruct_invoked[phpc_gc_count];
    }
}

static int phpc_destruct_index_of(void *obj)
{
    return phpc_gc_index_of(obj);
}

int phpc_destruct_already_invoked(void *obj)
{
    int idx = phpc_destruct_index_of(obj);

    return idx >= 0 ? (int) phpc_destruct_invoked[idx] : 0;
}

void phpc_destruct_mark_invoked(void *obj)
{
    int idx = phpc_destruct_index_of(obj);

    if (idx >= 0) {
        phpc_destruct_invoked[idx] = 1;
    }
}

void phpc_destruct_try_invoke(void *obj)
{
    phpc_object_header *hdr;

    if (NULL == obj || phpc_destruct_already_invoked(obj)) {
        return;
    }
    hdr = (phpc_object_header *) obj;
    if (!hdr->constructed) {
        return;
    }
    phpc_destruct_mark_invoked(obj);
    __object__invoke_destructor(obj);
}

void phpc_object_release_storage(void *obj)
{
    phpc_object_header *hdr;

    if (NULL == obj) {
        return;
    }
    hdr = (phpc_object_header *) obj;
    phpc_weakref_clear_object_typed(obj, hdr->ref.typeinfo);
    phpc_gc_unregister(obj);
    __mm__free(obj);
}

void phpc_gc_run_shutdown_destructors(void)
{
    int i;
    int saved = phpc_destruct_allow_delref;

    phpc_destruct_allow_delref = 1;
    for (i = phpc_gc_count - 1; i >= 0; --i) {
        if (!phpc_destruct_invoked[i]) {
            phpc_destruct_try_invoke(phpc_gc_objects[i]);
        }
    }
    phpc_destruct_allow_delref = saved;
    /* Remaining registry entries without __destruct were freed via delref; release any left. */
    while (phpc_gc_count > 0) {
        void *obj = phpc_gc_objects[phpc_gc_count - 1];
        if (phpc_destruct_already_invoked(obj)) {
            phpc_gc_unregister(obj);
            continue;
        }
        phpc_object_release_storage(obj);
    }
}

static size_t phpc_object_header_bytes(void)
{
    return sizeof(phpc_object_header);
}

static int phpc_slot_is_object(void *slot)
{
    phpc_ref_head *head;

    if (NULL == slot) {
        return 0;
    }
    head = (phpc_ref_head *) slot;
    if ((head->typeinfo & PHPC_TYPEINFO_TYPEMASK) == PHPC_TYPEINFO_TYPE_OBJECT) {
        return 1;
    }

    return 0;
}

static __object__ *phpc_slot_read_object(void *slot)
{
    __value__ *boxed;
    int8_t kind;

    if (NULL == slot) {
        return NULL;
    }

    boxed = (__value__ *) slot;
    kind = (int8_t) (boxed->type & 0x7f);
    if (PHPC_TYPE_OBJECT == kind) {
        return __value__readObject(boxed);
    }
    if (phpc_slot_is_object(slot)) {
        return (__object__ *) slot;
    }

    return NULL;
}

static void phpc_gc_visit_object(int obj_index);

static void phpc_gc_visit_slot(void *slot)
{
    __object__ *child;
    int child_index;

    child = phpc_slot_read_object(slot);
    if (NULL == child) {
        return;
    }
    child_index = phpc_gc_index_of(child);
    if (child_index < 0 || phpc_gc_marked[child_index]) {
        return;
    }
    phpc_gc_marked[child_index] = 1;
    phpc_gc_visit_object(child_index);
}

static void phpc_gc_visit_object(int obj_index)
{
    void *obj = phpc_gc_objects[obj_index];
    int prop_count = phpc_gc_prop_counts[obj_index];
    char *base = (char *) obj;
    size_t header = phpc_object_header_bytes();
    int slot;

    for (slot = 0; slot < prop_count; ++slot) {
        void **slot_ptr = (void **) (base + header + (size_t) slot * sizeof(void *));
        phpc_gc_visit_slot(*slot_ptr);
    }
}

static void phpc_gc_clear_slots_pointing_to(void *target)
{
    int i;
    int slot;

    for (i = 0; i < phpc_gc_count; ++i) {
        void *obj = phpc_gc_objects[i];
        int prop_count = phpc_gc_prop_counts[i];
        char *base = (char *) obj;
        size_t header = phpc_object_header_bytes();

        for (slot = 0; slot < prop_count; ++slot) {
            void **slot_ptr = (void **) (base + header + (size_t) slot * sizeof(void *));
            __object__ *child = phpc_slot_read_object(*slot_ptr);

            if (child == target) {
                *slot_ptr = NULL;
            }
        }
    }
}

static void phpc_gc_free_object(void *obj)
{
    phpc_weakref_clear_object(obj);
    phpc_gc_clear_slots_pointing_to(obj);
    phpc_gc_unregister(obj);
    __mm__free(obj);
}

static int phpc_gc_collect_cycles_impl(void)
{
    int i;
    int slot;
    int collected = 0;

    if (!phpc_gc_enabled || phpc_gc_count <= 0) {
        return 0;
    }

    memset(phpc_gc_marked, 0, (size_t) phpc_gc_count);
    memset(phpc_gc_inbound, 0, (size_t) phpc_gc_count * sizeof(int));

    for (i = 0; i < phpc_gc_count; ++i) {
        void *obj = phpc_gc_objects[i];
        int prop_count = phpc_gc_prop_counts[i];
        char *base = (char *) obj;
        size_t header = phpc_object_header_bytes();

        for (slot = 0; slot < prop_count; ++slot) {
            void **slot_ptr = (void **) (base + header + (size_t) slot * sizeof(void *));
            __object__ *child = phpc_slot_read_object(*slot_ptr);
            int child_index;

            if (NULL == child) {
                continue;
            }
            child_index = phpc_gc_index_of(child);
            if (child_index >= 0) {
                ++phpc_gc_inbound[child_index];
            }
        }
    }

    for (i = 0; i < phpc_gc_count; ++i) {
        phpc_object_header *hdr = (phpc_object_header *) phpc_gc_objects[i];
        int32_t refcount = hdr->ref.refcount;
        int roots = refcount - phpc_gc_inbound[i];

        if (roots > 0) {
            if (!phpc_gc_marked[i]) {
                phpc_gc_marked[i] = 1;
                phpc_gc_visit_object(i);
            }
        }
    }

    for (i = 0; i < phpc_gc_count; ) {
        if (!phpc_gc_marked[i]) {
            void *obj = phpc_gc_objects[i];
            phpc_gc_free_object(obj);
            ++collected;
            continue;
        }
        ++i;
    }

    return collected;
}

long long __compiler_gc_collect_cycles(void)
{
    if (!phpc_gc_enabled) {
        return 0;
    }

    return (long long) phpc_gc_collect_cycles_impl();
}
