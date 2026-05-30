/*
 * gettype() boxed-value helper for AOT/JIT (#3618).
 *
 * @see ext/standard/basic_functions.c PHP_FUNCTION(gettype)
 */

#include <stdint.h>
#include <string.h>

typedef struct __value__ __value__;
typedef struct __string__ __string__;

typedef struct __value__ {
    int8_t type;
    int8_t value[8];
} __value__;

extern __string__ *__string__init(long long size, const char *value);
extern long long __value__readLong(__value__ *);
extern int __compiler_is_resource(int64_t handle);

#define PHPC_TYPE_NULL 0
#define PHPC_TYPE_NATIVE_LONG 1
#define PHPC_TYPE_NATIVE_BOOL 2
#define PHPC_TYPE_NATIVE_DOUBLE 3
#define PHPC_TYPE_STRING 4
#define PHPC_TYPE_OBJECT 5
#define PHPC_TYPE_HASHTABLE 7

static __string__ *gettype_label(const char *label)
{
    size_t len = strlen(label);

    return __string__init((long long) len, label);
}

__string__ *__compiler_gettype(__value__ *v)
{
    char type;

    if (NULL == v) {
        return gettype_label("unknown");
    }
    type = (char) (v->type & 0x7f);
    switch (type) {
        case PHPC_TYPE_NULL:
            return gettype_label("NULL");
        case PHPC_TYPE_NATIVE_BOOL:
            return gettype_label("boolean");
        case PHPC_TYPE_NATIVE_LONG:
            if (__compiler_is_resource(__value__readLong(v))) {
                return gettype_label("resource");
            }

            return gettype_label("integer");
        case PHPC_TYPE_NATIVE_DOUBLE:
            return gettype_label("double");
        case PHPC_TYPE_STRING:
            return gettype_label("string");
        case PHPC_TYPE_OBJECT:
            return gettype_label("object");
        case PHPC_TYPE_HASHTABLE:
            return gettype_label("array");
        default:
            return gettype_label("unknown");
    }
}
