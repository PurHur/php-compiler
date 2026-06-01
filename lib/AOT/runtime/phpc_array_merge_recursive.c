/*
 * array_merge_recursive() overlay — ext/standard/array.c php_array_merge_recursive() parity (#3297).
 */

#include <stddef.h>
#include <stdint.h>
#include <string.h>

typedef struct __string__ __string__;
typedef struct __hashtable__ __hashtable__;
typedef struct __value__ __value__;

typedef struct __ref__ {
    int32_t refcount;
    int32_t typeinfo;
} __ref__;

typedef struct __value__ {
    int8_t type;
    int8_t value[8];
} __value__;

typedef struct __strkey_value__ {
    int8_t type;
    int8_t value[8];
    int8_t pad[7];
} __strkey_value__;

typedef struct __strkey_node__ {
    __ref__ ref;
    __string__ *key;
    __strkey_value__ value;
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
extern size_t __hashtable__getNumElements(__hashtable__ *ht);
extern int __hashtable__offsetIsSetStringKey(__hashtable__ *ht, __string__ *key);
extern __value__ *__hashtable__peekStringKeyValue(__hashtable__ *ht, __string__ *key);
extern __value__ *__hashtable__readStringKeyValue(__hashtable__ *ht, __string__ *key);
extern void __hashtable__setStringKeyLong(__hashtable__ *ht, __string__ *key, long long val);
extern void __hashtable__setStringKeyBool(__hashtable__ *ht, __string__ *key, int val);
extern void __hashtable__setStringKeyString(__hashtable__ *ht, __string__ *key, __string__ *val);
extern void __hashtable__setStringKeyHashtable(__hashtable__ *ht, __string__ *key, __hashtable__ *child);

extern long long __value__readLong(__value__ *v);
extern double __value__readDouble(__value__ *v);
extern __string__ *__value__readString(__value__ *v);
extern __hashtable__ *__value__readHashtable(__value__ *v);
extern void __value__writeLong(__value__ *out, long long val);
extern void __value__writeDouble(__value__ *out, double val);
extern void __value__writeString(__value__ *out, __string__ *val);
extern void __value__writeHashtable(__value__ *out, __hashtable__ *ht);
extern void __value__writeNull(__value__ *out);

extern __string__ *__string__separate(__string__ *s);

void __compiler_array_merge_recursive_overlay(__hashtable__ *dest, __hashtable__ *src);

static int amr_value_kind(const __value__ *v)
{
    return (int) (v->type & 0x7f);
}

static void amr_ht_append_value(__hashtable__ *dest, __value__ *src)
{
    size_t index = dest->nextFreeElement;
    int kind = amr_value_kind(src);

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

static void amr_ht_set_string_key_value(__hashtable__ *dest, __string__ *key, __value__ *src)
{
    int kind = amr_value_kind(src);

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
            /* No setStringKeyDouble in runtime; store via packed fallback is unavailable — use long cast. */
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

static __hashtable__ *amr_ht_combine_values(__value__ *existing, __value__ *overlay)
{
    int ekind = amr_value_kind(existing);
    int okind = amr_value_kind(overlay);

    if (ekind == PHPC_TYPE_HASHTABLE && okind == PHPC_TYPE_HASHTABLE) {
        __hashtable__ *combined = __hashtable__alloc();
        __hashtable__ *eht = __value__readHashtable(existing);
        __hashtable__ *oht = __value__readHashtable(overlay);

        __compiler_array_merge_recursive_overlay(combined, eht);
        __compiler_array_merge_recursive_overlay(combined, oht);

        return combined;
    }

    if (ekind == PHPC_TYPE_HASHTABLE) {
        __hashtable__ *combined = __value__readHashtable(existing);

        amr_ht_append_value(combined, overlay);

        return combined;
    }

    if (okind == PHPC_TYPE_HASHTABLE) {
        __hashtable__ *combined = __hashtable__alloc();
        __hashtable__ *oht = __value__readHashtable(overlay);
        size_t i;

        amr_ht_append_value(combined, existing);
        for (i = 0; i < oht->nextFreeElement; ++i) {
            if (__hashtable__offsetIsSet(oht, i)) {
                amr_ht_append_value(combined, &oht->values[i]);
            }
        }
        for (
            __strkey_node__ *node = oht->strKeys;
            NULL != node;
            node = node->next
        ) {
            amr_ht_set_string_key_value(combined, node->key, (__value__ *) &node->value);
        }

        return combined;
    }

    {
        __hashtable__ *combined = __hashtable__alloc();

        if (ekind == PHPC_TYPE_NATIVE_LONG && okind == PHPC_TYPE_NATIVE_LONG) {
            __hashtable__setLongAt(combined, 0, __value__readLong(existing));
            __hashtable__setLongAt(combined, 1, __value__readLong(overlay));
        } else {
            amr_ht_append_value(combined, existing);
            amr_ht_append_value(combined, overlay);
        }

        return combined;
    }
}

static int amr_value_is_null(const __value__ *v)
{
    return NULL == v || PHPC_TYPE_NULL == amr_value_kind(v);
}

static void amr_merge_string_key(__hashtable__ *dest, __string__ *key, __value__ *overlay_val)
{
    __value__ *existing_val = __hashtable__peekStringKeyValue(dest, key);

    if (amr_value_is_null(existing_val)) {
        amr_ht_set_string_key_value(dest, key, overlay_val);

        return;
    }

    {
        int ekind = amr_value_kind(existing_val);
        int okind = amr_value_kind(overlay_val);

        if (ekind == PHPC_TYPE_HASHTABLE && okind == PHPC_TYPE_HASHTABLE) {
            __hashtable__ *merged = __hashtable__alloc();

            __compiler_array_merge_recursive_overlay(
                merged,
                __value__readHashtable(existing_val)
            );
            __compiler_array_merge_recursive_overlay(
                merged,
                __value__readHashtable(overlay_val)
            );
            __hashtable__setStringKeyHashtable(dest, key, merged);

            return;
        }

        {
            __hashtable__ *combined = amr_ht_combine_values(existing_val, overlay_val);

            __hashtable__setStringKeyHashtable(dest, key, combined);
        }
    }
}

void __compiler_array_merge_recursive_overlay(__hashtable__ *dest, __hashtable__ *src)
{
    size_t i;

    if (NULL == dest || NULL == src) {
        return;
    }

    for (i = 0; i < src->nextFreeElement; ++i) {
        if (__hashtable__offsetIsSet(src, i)) {
            amr_ht_append_value(dest, &src->values[i]);
        }
    }

    for (__strkey_node__ *node = src->strKeys; NULL != node; node = node->next) {
        amr_merge_string_key(dest, node->key, (__value__ *) &node->value);
    }
}
