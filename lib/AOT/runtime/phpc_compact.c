/*
 * compact() runtime for JIT/AOT — expand array/nested name arguments (issue #3468).
 * Mirrors ext/standard/basic_functions.c php_compact_vars().
 */

#include <stddef.h>
#include <stdint.h>
#include <string.h>

typedef struct __string__ __string__;
typedef struct __hashtable__ __hashtable__;

typedef struct __value__ {
    int8_t type;
    int8_t value[8];
} __value__;

typedef struct __strkey_node__ {
    void *ref;
    __string__ *key;
    __value__ value;
    struct __strkey_node__ *next;
} __strkey_node__;

typedef struct __hashtable__ {
    void *ref;
    size_t numElements;
    size_t nextFreeElement;
    size_t capacity;
    __value__ *values;
    __strkey_node__ *strKeys;
    void *objKeys;
} __hashtable__;

#define PHPC_TYPE_NATIVE_LONG 1
#define PHPC_TYPE_NATIVE_BOOL 2
#define PHPC_TYPE_NATIVE_DOUBLE 3
#define PHPC_TYPE_STRING 4
#define PHPC_TYPE_HASHTABLE 7

extern int __hashtable__offsetIsSet(__hashtable__ *ht, size_t index);
extern long long __value__readLong(__value__ *v);
extern double __value__readDouble(__value__ *v);
extern __string__ *__value__readString(__value__ *v);
extern __hashtable__ *__value__readHashtable(__value__ *v);
extern void __hashtable__setStringKeyString(__hashtable__ *ht, __string__ *key, __string__ *val);
extern void __hashtable__setStringKeyLong(__hashtable__ *ht, __string__ *key, long long val);
extern void __hashtable__setStringKeyBool(__hashtable__ *ht, __string__ *key, int val);
extern void __hashtable__setStringKeyHashtable(__hashtable__ *ht, __string__ *key, __hashtable__ *child);
extern __string__ *__string__init(long long size, const char *value);
extern __string__ *__string__separate(__string__ *s);

static int phpc_value_kind(const __value__ *v)
{
    return (int) (v->type & 0x7f);
}

static const char *phpc_string_cstr(__string__ *s)
{
    if (NULL == s) {
        return "";
    }

    return (const char *) s + sizeof(void *) + sizeof(long long);
}

static void phpc_compact_store_slot(
    __hashtable__ *result,
    __string__ *key,
    __value__ *slot
)
{
    int kind = phpc_value_kind(slot);

    switch (kind) {
        case PHPC_TYPE_NATIVE_LONG:
            __hashtable__setStringKeyLong(result, key, __value__readLong(slot));
            break;
        case PHPC_TYPE_NATIVE_BOOL:
            __hashtable__setStringKeyBool(
                result,
                key,
                __value__readLong(slot) ? 1 : 0
            );
            break;
        case PHPC_TYPE_NATIVE_DOUBLE:
            __hashtable__setStringKeyLong(result, key, (long long) __value__readDouble(slot));
            break;
        case PHPC_TYPE_STRING:
            __hashtable__setStringKeyString(
                result,
                key,
                __string__separate(__value__readString(slot))
            );
            break;
        case PHPC_TYPE_HASHTABLE:
            __hashtable__setStringKeyHashtable(
                result,
                key,
                __value__readHashtable(slot)
            );
            break;
        default:
            break;
    }
}

static void phpc_compact_apply_name(
    __hashtable__ *result,
    const char *name,
    const char **binding_names,
    __value__ **binding_slots,
    size_t binding_count
)
{
    size_t i;
    __string__ *key;

    if (NULL == name || '\0' == name[0]) {
        return;
    }

    for (i = 0; i < binding_count; ++i) {
        if (0 == strcmp(binding_names[i], name)) {
            key = __string__init((long long) strlen(name), name);
            phpc_compact_store_slot(result, key, binding_slots[i]);

            return;
        }
    }
}

static void phpc_compact_collect_value(
    __hashtable__ *result,
    __value__ *v,
    const char **binding_names,
    __value__ **binding_slots,
    size_t binding_count
)
{
    size_t i;
    __strkey_node__ *node;

    if (NULL == v) {
        return;
    }

    switch (phpc_value_kind(v)) {
        case PHPC_TYPE_STRING:
            phpc_compact_apply_name(
                result,
                phpc_string_cstr(__value__readString(v)),
                binding_names,
                binding_slots,
                binding_count
            );
            break;
        case PHPC_TYPE_HASHTABLE: {
            __hashtable__ *ht = __value__readHashtable(v);

            if (NULL == ht) {
                break;
            }
            for (i = 0; i < ht->nextFreeElement; ++i) {
                if (__hashtable__offsetIsSet(ht, i)) {
                    phpc_compact_collect_value(
                        result,
                        &ht->values[i],
                        binding_names,
                        binding_slots,
                        binding_count
                    );
                }
            }
            for (node = ht->strKeys; NULL != node; node = node->next) {
                phpc_compact_collect_value(
                    result,
                    &node->value,
                    binding_names,
                    binding_slots,
                    binding_count
                );
            }
            break;
        }
        default:
            break;
    }
}

void __compiler_compact_apply_arg(
    __hashtable__ *result,
    __value__ *arg,
    const char **binding_names,
    __value__ **binding_slots,
    long long binding_count
)
{
    if (
        NULL == result
        || NULL == arg
        || NULL == binding_names
        || NULL == binding_slots
        || binding_count <= 0
    ) {
        return;
    }

    phpc_compact_collect_value(
        result,
        arg,
        binding_names,
        binding_slots,
        (size_t) binding_count
    );
}
