/*
 * array_replace_recursive() overlay — ext/standard/array.c php_array_replace_recursive() parity (#3166).
 */

#include <stddef.h>
#include <stdint.h>
#include <string.h>

typedef struct __string__ __string__;
typedef struct __hashtable__ __hashtable__;
typedef struct __value__ __value__;

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

#define PHPC_TYPE_NULL 0
#define PHPC_TYPE_NATIVE_LONG 1
#define PHPC_TYPE_NATIVE_BOOL 2
#define PHPC_TYPE_NATIVE_DOUBLE 3
#define PHPC_TYPE_STRING 4
#define PHPC_TYPE_HASHTABLE 7

extern __hashtable__ *__hashtable__alloc(void);
extern void __hashtable__grow(__hashtable__ *ht, size_t minCap);
extern int __hashtable__offsetIsSet(__hashtable__ *ht, size_t index);
extern void __hashtable__setLongAt(__hashtable__ *ht, size_t index, long long val);
extern void __hashtable__setDoubleAt(__hashtable__ *ht, size_t index, double val);
extern void __hashtable__setBoolAt(__hashtable__ *ht, size_t index, int val);
extern void __hashtable__setStringAt(__hashtable__ *ht, size_t index, __string__ *val);
extern void __hashtable__setHashtableAt(__hashtable__ *ht, size_t index, __hashtable__ *child);
extern void __hashtable__setNullAt(__hashtable__ *ht, size_t index);
extern __value__ *__hashtable__peekStringKeyValue(__hashtable__ *ht, __string__ *key);
extern __value__ *__hashtable__readStringKeyValue(__hashtable__ *ht, __string__ *key);
extern void __hashtable__setStringKeyLong(__hashtable__ *ht, __string__ *key, long long val);
extern void __hashtable__setStringKeyBool(__hashtable__ *ht, __string__ *key, int val);
extern void __hashtable__setStringKeyString(__hashtable__ *ht, __string__ *key, __string__ *val);
extern void __hashtable__setStringKeyHashtable(__hashtable__ *ht, __string__ *key, __hashtable__ *child);

extern long long __value__readLong(__value__ *v);
extern __string__ *__value__readString(__value__ *v);
extern __hashtable__ *__value__readHashtable(__value__ *v);

extern __string__ *__string__separate(__string__ *s);

void __compiler_array_replace_recursive_overlay(__hashtable__ *dest, __hashtable__ *src);

static int arr_value_kind(const __value__ *v)
{
    return (int) (v->type & 0x7f);
}

static int arr_value_is_null(const __value__ *v)
{
    return NULL == v || PHPC_TYPE_NULL == arr_value_kind(v);
}

static void arr_ht_set_packed_value(__hashtable__ *dest, size_t index, __value__ *src)
{
    int kind = arr_value_kind(src);

    if (index >= dest->capacity) {
        __hashtable__grow(dest, index + 1);
    }

    switch (kind) {
        case PHPC_TYPE_NATIVE_LONG:
            __hashtable__setLongAt(dest, index, __value__readLong(src));
            break;
        case PHPC_TYPE_NATIVE_BOOL:
            __hashtable__setBoolAt(dest, index, __value__readLong(src) ? 1 : 0);
            break;
        case PHPC_TYPE_NATIVE_DOUBLE: {
            double dval;

            memcpy(&dval, src->value, sizeof dval);
            __hashtable__setDoubleAt(dest, index, dval);
            break;
        }
        case PHPC_TYPE_STRING:
            __hashtable__setStringAt(dest, index, __value__readString(src));
            break;
        case PHPC_TYPE_HASHTABLE:
            __hashtable__setHashtableAt(dest, index, __value__readHashtable(src));
            break;
        default:
            __hashtable__setNullAt(dest, index);
            break;
    }
}

static void arr_ht_set_string_key_value(__hashtable__ *dest, __string__ *key, __value__ *src)
{
    int kind = arr_value_kind(src);

    switch (kind) {
        case PHPC_TYPE_NATIVE_LONG:
            __hashtable__setStringKeyLong(dest, key, __value__readLong(src));
            break;
        case PHPC_TYPE_NATIVE_BOOL:
            __hashtable__setStringKeyBool(dest, key, __value__readLong(src) ? 1 : 0);
            break;
        case PHPC_TYPE_NATIVE_DOUBLE: {
            double dval;

            memcpy(&dval, src->value, sizeof dval);
            __hashtable__setStringKeyLong(dest, key, (long long) dval);
            break;
        }
        case PHPC_TYPE_STRING: {
            __string__ *str = __value__readString(src);

            __hashtable__setStringKeyString(dest, key, __string__separate(str));
            break;
        }
        case PHPC_TYPE_HASHTABLE:
            __hashtable__setStringKeyHashtable(dest, key, __value__readHashtable(src));
            break;
        default:
            break;
    }
}

static __hashtable__ *arr_ht_shallow_copy(__hashtable__ *src)
{
    __hashtable__ *copy;
    size_t index;
    __strkey_node__ *node;

    if (NULL == src) {
        return __hashtable__alloc();
    }

    copy = __hashtable__alloc();
    for (index = 0; index < src->nextFreeElement; ++index) {
        if (__hashtable__offsetIsSet(src, index)) {
            arr_ht_set_packed_value(copy, index, &src->values[index]);
        }
    }
    for (node = src->strKeys; NULL != node; node = node->next) {
        arr_ht_set_string_key_value(copy, node->key, &node->value);
    }

    return copy;
}

static void arr_replace_packed_index(__hashtable__ *dest, size_t index, __value__ *overlay_val)
{
    if (
        __hashtable__offsetIsSet(dest, index)
        && arr_value_kind(overlay_val) == PHPC_TYPE_HASHTABLE
        && NULL != dest->values
        && !arr_value_is_null(&dest->values[index])
        && arr_value_kind(&dest->values[index]) == PHPC_TYPE_HASHTABLE
    ) {
        __hashtable__ *merged = __hashtable__alloc();

        __compiler_array_replace_recursive_overlay(
            merged,
            __value__readHashtable(&dest->values[index])
        );
        __compiler_array_replace_recursive_overlay(
            merged,
            __value__readHashtable(overlay_val)
        );
        __hashtable__setHashtableAt(dest, index, merged);

        return;
    }

    arr_ht_set_packed_value(dest, index, overlay_val);
}

static void arr_replace_string_key(__hashtable__ *dest, __string__ *key, __value__ *overlay_val)
{
    __value__ *existing_val = __hashtable__peekStringKeyValue(dest, key);

    if (arr_value_is_null(existing_val)) {
        arr_ht_set_string_key_value(dest, key, overlay_val);

        return;
    }

    {
        int ekind = arr_value_kind(existing_val);
        int okind = arr_value_kind(overlay_val);

        if (ekind == PHPC_TYPE_HASHTABLE && okind == PHPC_TYPE_HASHTABLE) {
            __hashtable__ *merged = __hashtable__alloc();

            __compiler_array_replace_recursive_overlay(
                merged,
                __value__readHashtable(existing_val)
            );
            __compiler_array_replace_recursive_overlay(
                merged,
                __value__readHashtable(overlay_val)
            );
            __hashtable__setStringKeyHashtable(dest, key, merged);

            return;
        }

        arr_ht_set_string_key_value(dest, key, overlay_val);
    }
}

void __compiler_array_replace_recursive_overlay(__hashtable__ *dest, __hashtable__ *src)
{
    size_t i;

    if (NULL == dest || NULL == src) {
        return;
    }

    for (i = 0; i < src->nextFreeElement; ++i) {
        if (__hashtable__offsetIsSet(src, i)) {
            arr_replace_packed_index(dest, i, &src->values[i]);
        }
    }

    for (__strkey_node__ *node = src->strKeys; NULL != node; node = node->next) {
        arr_replace_string_key(dest, node->key, &node->value);
    }
}
