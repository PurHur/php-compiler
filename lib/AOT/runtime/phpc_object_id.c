/*
 * get_object_id() helper for AOT/JIT (#3537).
 *
 * @see ext/standard/basic_functions.c PHP_FUNCTION(get_object_id)
 */

#include <stddef.h>
#include <stdint.h>

typedef struct __value__ __value__;
typedef void __object__;

typedef struct __value__ {
    int8_t type;
    int8_t value[8];
} __value__;

extern __object__ *__value__readObject(__value__ *v);

#define PHPC_TYPE_OBJECT 5

long long phpc_get_object_id_from_value(__value__ *v)
{
    __object__ *obj;

    if (NULL == v || ((int8_t) (v->type & 0x7f)) != PHPC_TYPE_OBJECT) {
        return 0;
    }
    obj = __value__readObject(v);
    if (NULL == obj) {
        return 0;
    }

    return (long long) (uintptr_t) obj;
}

long long phpc_get_object_id_from_object(__object__ *obj)
{
    if (NULL == obj) {
        return 0;
    }

    return (long long) (uintptr_t) obj;
}
