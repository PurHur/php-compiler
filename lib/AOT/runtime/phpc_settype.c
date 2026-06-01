/*
 * settype() in-place casts for JIT/AOT (issue #3151, ext/standard/type.c).
 */

#include <ctype.h>
#include <math.h>
#include <stddef.h>
#include <stdint.h>
#include <stdio.h>
#include <stdlib.h>
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
#define PHPC_TYPE_OBJECT 5
#define PHPC_TYPE_HASHTABLE 7

extern __string__ *__string__init(long long size, const char *value);
extern __string__ *__string__separate(__string__ *s);
extern __hashtable__ *__hashtable__alloc(void);
extern void __hashtable__setLongAt(__hashtable__ *ht, size_t index, long long val);
extern void __hashtable__setDoubleAt(__hashtable__ *ht, size_t index, double val);
extern void __hashtable__setBoolAt(__hashtable__ *ht, size_t index, int val);
extern void __hashtable__setStringAt(__hashtable__ *ht, size_t index, __string__ *val);
extern void __hashtable__setHashtableAt(__hashtable__ *ht, size_t index, __hashtable__ *child);
extern void __hashtable__setNullAt(__hashtable__ *ht, size_t index);
extern void __hashtable__setStringKeyLong(__hashtable__ *ht, __string__ *key, long long val);
extern void __hashtable__setStringKeyBool(__hashtable__ *ht, __string__ *key, int val);
extern void __hashtable__setStringKeyString(__hashtable__ *ht, __string__ *key, __string__ *val);
extern void __hashtable__setStringKeyHashtable(__hashtable__ *ht, __string__ *key, __hashtable__ *child);
extern size_t __hashtable__getNumElements(__hashtable__ *ht);

extern long long __value__readLong(__value__ *v);
extern double __value__readDouble(__value__ *v);
extern __string__ *__value__readString(__value__ *v);
extern __hashtable__ *__value__readHashtable(__value__ *v);
extern void __value__writeLong(__value__ *out, long long val);
extern void __value__writeDouble(__value__ *out, double val);
extern void __value__writeString(__value__ *out, __string__ *val);
extern void __value__writeBool(__value__ *out, int val);
extern void __value__writeHashtable(__value__ *out, __hashtable__ *ht);
extern void __value__writeNull(__value__ *out);

extern void __compiler_jit_raise_logic_exception(const char *msg, unsigned long len);

static size_t phpc_strlen(__string__ *s)
{
    if (NULL == s) {
        return 0;
    }

    return (size_t) *((long long *) ((char *) s + sizeof(void *)));
}

static const char *phpc_strdata(__string__ *s)
{
    if (NULL == s) {
        return "";
    }

    return (const char *) s + sizeof(void *) + sizeof(long long);
}

static int phpc_value_kind(const __value__ *v)
{
    return (int) (v->type & 0x7f);
}

static int phpc_value_is_null(const __value__ *v)
{
    return PHPC_TYPE_NULL == phpc_value_kind(v);
}

static void phpc_raise_logic(const char *msg)
{
    __compiler_jit_raise_logic_exception(msg, strlen(msg));
}

static void phpc_raise_value_error(const char *msg)
{
    __compiler_jit_raise_logic_exception(msg, strlen(msg));
}

static int phpc_value_is_truthy(__value__ *v)
{
    int kind = phpc_value_kind(v);

    switch (kind) {
        case PHPC_TYPE_NULL:
            return 0;
        case PHPC_TYPE_NATIVE_BOOL:
        case PHPC_TYPE_NATIVE_LONG:
            return __value__readLong(v) != 0;
        case PHPC_TYPE_NATIVE_DOUBLE:
            return __value__readDouble(v) != 0.0;
        case PHPC_TYPE_STRING: {
            const char *data = phpc_strdata(__value__readString(v));
            size_t len = phpc_strlen(__value__readString(v));

            if (0 == len) {
                return 0;
            }
            if (1 == len && '0' == data[0]) {
                return 0;
            }

            return 1;
        }
        case PHPC_TYPE_HASHTABLE:
            return __hashtable__getNumElements(__value__readHashtable(v)) > 0;
        default:
            return 1;
    }
}

static void phpc_ht_append_value(__hashtable__ *dest, __value__ *src)
{
    size_t index = dest->nextFreeElement;
    int kind = phpc_value_kind(src);

    switch (kind) {
        case PHPC_TYPE_NATIVE_LONG:
            __hashtable__setLongAt(dest, index, __value__readLong(src));
            break;
        case PHPC_TYPE_NATIVE_BOOL:
            __hashtable__setBoolAt(dest, index, __value__readLong(src) ? 1 : 0);
            break;
        case PHPC_TYPE_NATIVE_DOUBLE:
            __hashtable__setDoubleAt(dest, index, __value__readDouble(src));
            break;
        case PHPC_TYPE_STRING:
            __hashtable__setStringAt(dest, index, __string__separate(__value__readString(src)));
            break;
        case PHPC_TYPE_HASHTABLE:
            __hashtable__setHashtableAt(dest, index, __value__readHashtable(src));
            break;
        case PHPC_TYPE_NULL:
            __hashtable__setNullAt(dest, index);
            break;
        default:
            break;
    }
}

static void phpc_ht_set_string_key_value(__hashtable__ *dest, __string__ *key, __value__ *src)
{
    int kind = phpc_value_kind(src);

    switch (kind) {
        case PHPC_TYPE_NATIVE_LONG:
            __hashtable__setStringKeyLong(dest, key, __value__readLong(src));
            break;
        case PHPC_TYPE_NATIVE_BOOL:
            __hashtable__setStringKeyBool(dest, key, __value__readLong(src) ? 1 : 0);
            break;
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

static __hashtable__ *phpc_hashtable_replace_copy(__hashtable__ *src)
{
    __hashtable__ *copy;
    size_t index;
    __strkey_node__ *node;

    if (NULL == src) {
        return __hashtable__alloc();
    }

    copy = __hashtable__alloc();
    for (index = 0; index < src->nextFreeElement; ++index) {
        if (!phpc_value_is_null(&src->values[index])) {
            phpc_ht_append_value(copy, &src->values[index]);
        }
    }
    for (node = src->strKeys; NULL != node; node = node->next) {
        phpc_ht_set_string_key_value(copy, node->key, &node->value);
    }

    return copy;
}

static void phpc_to_integer(__value__ *slot, __value__ *src)
{
    int kind = phpc_value_kind(src);

    switch (kind) {
        case PHPC_TYPE_NATIVE_LONG:
            __value__writeLong(slot, __value__readLong(src));
            return;
        case PHPC_TYPE_NATIVE_DOUBLE:
            __value__writeLong(slot, (long long) __value__readDouble(src));
            return;
        case PHPC_TYPE_NATIVE_BOOL:
            __value__writeLong(slot, __value__readLong(src) ? 1 : 0);
            return;
        case PHPC_TYPE_STRING:
            __value__writeLong(slot, strtoll(phpc_strdata(__value__readString(src)), NULL, 10));
            return;
        case PHPC_TYPE_NULL:
            __value__writeLong(slot, 0);
            return;
        case PHPC_TYPE_HASHTABLE:
            __value__writeLong(
                slot,
                __hashtable__getNumElements(__value__readHashtable(src)) > 0 ? 1 : 0
            );
            return;
        default:
            phpc_raise_logic("settype() to integer does not support this value type in this compiler build");
    }
}

static void phpc_to_float(__value__ *slot, __value__ *src)
{
    int kind = phpc_value_kind(src);

    switch (kind) {
        case PHPC_TYPE_NATIVE_DOUBLE:
            __value__writeDouble(slot, __value__readDouble(src));
            return;
        case PHPC_TYPE_NATIVE_LONG:
            __value__writeDouble(slot, (double) __value__readLong(src));
            return;
        case PHPC_TYPE_NATIVE_BOOL:
            __value__writeDouble(slot, __value__readLong(src) ? 1.0 : 0.0);
            return;
        case PHPC_TYPE_STRING:
            __value__writeDouble(slot, strtod(phpc_strdata(__value__readString(src)), NULL));
            return;
        case PHPC_TYPE_NULL:
            __value__writeDouble(slot, 0.0);
            return;
        case PHPC_TYPE_HASHTABLE:
            __value__writeDouble(
                slot,
                __hashtable__getNumElements(__value__readHashtable(src)) > 0 ? 1.0 : 0.0
            );
            return;
        default:
            phpc_raise_logic("settype() to float does not support this value type in this compiler build");
    }
}

static void phpc_to_bool(__value__ *slot, __value__ *src)
{
    __value__writeBool(slot, phpc_value_is_truthy(src) ? 1 : 0);
}

static void phpc_to_string(__value__ *slot, __value__ *src)
{
    int kind = phpc_value_kind(src);
    char buf[64];

    if (PHPC_TYPE_NULL == kind) {
        __value__writeString(slot, __string__init(0, ""));

        return;
    }
    if (PHPC_TYPE_HASHTABLE == kind) {
        __value__writeString(slot, __string__init(5, "Array"));

        return;
    }
    if (PHPC_TYPE_STRING == kind) {
        __value__writeString(slot, __string__separate(__value__readString(src)));

        return;
    }
    if (PHPC_TYPE_NATIVE_LONG == kind || PHPC_TYPE_NATIVE_BOOL == kind) {
        snprintf(buf, sizeof buf, "%lld", __value__readLong(src));
        __value__writeString(slot, __string__init((long long) strlen(buf), buf));

        return;
    }
    if (PHPC_TYPE_NATIVE_DOUBLE == kind) {
        snprintf(buf, sizeof buf, "%g", __value__readDouble(src));
        __value__writeString(slot, __string__init((long long) strlen(buf), buf));

        return;
    }

    phpc_raise_logic("settype() to string does not support this value type in this compiler build");
}

static void phpc_to_array(__value__ *slot, __value__ *src)
{
    int kind = phpc_value_kind(src);

    if (PHPC_TYPE_HASHTABLE == kind) {
        __value__writeHashtable(slot, phpc_hashtable_replace_copy(__value__readHashtable(src)));

        return;
    }
    if (PHPC_TYPE_NULL == kind) {
        __value__writeHashtable(slot, __hashtable__alloc());

        return;
    }

    {
        __hashtable__ *ht = __hashtable__alloc();

        phpc_ht_append_value(ht, src);
        __value__writeHashtable(slot, ht);
    }
}

static int phpc_type_name_matches(const char *type, const char *name)
{
    return 0 == strcasecmp(type, name);
}

void __compiler_settype(void *slot_ptr, __string__ *typeName)
{
    char type_buf[32];
    const char *type_raw;
    size_t type_len;
    size_t i;
    __value__ scratch;
    __value__ *slot = (__value__ *) slot_ptr;

    if (NULL == slot) {
        return;
    }

    if (NULL == typeName) {
        phpc_raise_value_error("settype(): Argument #2 ($type) must be of type string");

        return;
    }

    type_raw = phpc_strdata(typeName);
    type_len = phpc_strlen(typeName);
    if (type_len >= sizeof type_buf) {
        type_len = sizeof type_buf - 1;
    }
    for (i = 0; i < type_len; ++i) {
        type_buf[i] = (char) tolower((unsigned char) type_raw[i]);
    }
    type_buf[type_len] = '\0';

    memcpy(&scratch, slot, sizeof scratch);

    if (phpc_type_name_matches(type_buf, "integer") || phpc_type_name_matches(type_buf, "int")) {
        phpc_to_integer(slot, &scratch);

        return;
    }
    if (phpc_type_name_matches(type_buf, "double") || phpc_type_name_matches(type_buf, "float")) {
        phpc_to_float(slot, &scratch);

        return;
    }
    if (phpc_type_name_matches(type_buf, "bool") || phpc_type_name_matches(type_buf, "boolean")) {
        phpc_to_bool(slot, &scratch);

        return;
    }
    if (phpc_type_name_matches(type_buf, "string")) {
        phpc_to_string(slot, &scratch);

        return;
    }
    if (phpc_type_name_matches(type_buf, "array")) {
        phpc_to_array(slot, &scratch);

        return;
    }
    if (phpc_type_name_matches(type_buf, "null")) {
        __value__writeNull(slot);

        return;
    }
    if (phpc_type_name_matches(type_buf, "object")) {
        phpc_raise_logic("settype() to object is not supported in this compiler build");

        return;
    }
    if (phpc_type_name_matches(type_buf, "resource")) {
        phpc_raise_value_error("Cannot convert to resource type");

        return;
    }

    phpc_raise_value_error("settype(): Argument #2 ($type) must be a valid type");
}
