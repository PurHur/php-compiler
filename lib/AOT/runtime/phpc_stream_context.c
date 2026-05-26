/*
 * stream_context_create() representation for JIT/AOT (issues #1377, #2457).
 */

#include <stdint.h>
#include <string.h>

typedef struct __string__ __string__;

typedef struct __ref__ {
    void *vtable;
    int32_t refcount;
    int32_t typeinfo;
} __ref__;

typedef struct __value__ {
    int8_t type;
    int8_t value[8];
} __value__;

typedef struct __strkey_node__ {
    __ref__ ref;
    __string__ *key;
    __value__ value;
    struct __strkey_node__ *next;
} __strkey_node__;

typedef struct __hashtable__ {
    __ref__ ref;
    size_t numElements;
    size_t nextFreeElement;
    size_t capacity;
    __value__ *values;
    __strkey_node__ *strKeys;
    void *objKeys;
} __hashtable__;

extern __string__ *__string__init(long long size, const char *value);
extern __hashtable__ *__hashtable__alloc(void);
extern void __hashtable__setStringKeyString(__hashtable__ *ht, __string__ *key, __string__ *value);
extern void __hashtable__setStringKeyLong(__hashtable__ *ht, __string__ *key, long long value);
extern void __hashtable__setStringKeyBool(__hashtable__ *ht, __string__ *key, int value);
extern void __hashtable__setStringKeyHashtable(__hashtable__ *ht, __string__ *key, __hashtable__ *value);

#define PHPC_TYPE_NULL 0
#define PHPC_TYPE_NATIVE_LONG 1
#define PHPC_TYPE_NATIVE_BOOL 2
#define PHPC_TYPE_NATIVE_DOUBLE 3
#define PHPC_TYPE_STRING 4
#define PHPC_TYPE_HASHTABLE 7
#define PHPC_STREAM_CONTEXT_MARKER "__phpc_stream_context"

static __string__ *cstr_to_string(const char *cstr)
{
    return __string__init((long long) strlen(cstr), cstr);
}

static int phpc_stream_context_next_id = 0;

static void phpc_stream_context_merge_scalar(
    __hashtable__ *dest,
    __strkey_node__ *node
)
{
    int8_t type = node->value.type;

    if (PHPC_TYPE_NULL == type) {
        __hashtable__setStringKeyString(dest, node->key, cstr_to_string(""));
    } else if (PHPC_TYPE_NATIVE_BOOL == type) {
        __hashtable__setStringKeyBool(dest, node->key, node->value.value[0] ? 1 : 0);
    } else if (PHPC_TYPE_NATIVE_LONG == type) {
        long long n;

        memcpy(&n, node->value.value, sizeof n);
        __hashtable__setStringKeyLong(dest, node->key, n);
    } else if (PHPC_TYPE_NATIVE_DOUBLE == type) {
        double d;
        long long as_long;

        memcpy(&d, node->value.value, sizeof d);
        as_long = (long long) d;
        __hashtable__setStringKeyLong(dest, node->key, as_long);
    } else if (PHPC_TYPE_STRING == type || (type & 0x7f) == PHPC_TYPE_STRING) {
        __string__ *str = *((__string__ **) node->value.value);

        __hashtable__setStringKeyString(dest, node->key, str);
    }
}

static void phpc_stream_context_merge_options(__hashtable__ *dest, __hashtable__ *src)
{
    __strkey_node__ *node;

    if (NULL == dest || NULL == src) {
        return;
    }
    for (node = src->strKeys; NULL != node; node = node->next) {
        int8_t type = node->value.type;

        if ((type & 0x7f) == PHPC_TYPE_HASHTABLE) {
            __hashtable__ *nested = *((__hashtable__ **) node->value.value);
            __hashtable__ *child = __hashtable__alloc();

            phpc_stream_context_merge_options(child, nested);
            __hashtable__setStringKeyHashtable(dest, node->key, child);
        } else {
            phpc_stream_context_merge_scalar(dest, node);
        }
    }
}

/** Build stream-context array (options copy + marker id). Params reserved for VM host resource (#1377). */
__hashtable__ *__phpc_stream_context_create(__hashtable__ *options, __hashtable__ *params)
{
    __hashtable__ *out;
    int id;

    (void) params;
    out = __hashtable__alloc();
    if (NULL != options) {
        phpc_stream_context_merge_options(out, options);
    }
    id = ++phpc_stream_context_next_id;
    __hashtable__setStringKeyLong(out, cstr_to_string(PHPC_STREAM_CONTEXT_MARKER), (long long) id);

    return out;
}
