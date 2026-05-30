/*
 * Spaceship (<=>) for boxed values and native hashtables (issue #3672).
 *
 * @see Zend/zend_operators.c compare_function(), zend_compare_arrays()
 */

#include <stdint.h>
#include <stdlib.h>
#include <string.h>

typedef struct __string__ __string__;
typedef struct __hashtable__ __hashtable__;

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

extern long long __value__readLong(__value__ *v);
extern __string__ *__value__readString(__value__ *v);
extern __hashtable__ *__value__readHashtable(__value__ *v);

long long __value__spaceship(__value__ *left, __value__ *right);
long long __hashtable__compareSpaceship(__hashtable__ *left, __hashtable__ *right);

#define PHPC_TYPE_NULL 0
#define PHPC_TYPE_NATIVE_LONG 1
#define PHPC_TYPE_NATIVE_BOOL 2
#define PHPC_TYPE_NATIVE_DOUBLE 3
#define PHPC_TYPE_STRING 4
#define PHPC_TYPE_HASHTABLE 7

static int phpc_value_kind(const __value__ *v)
{
    return (int) (v->type & 0x7f);
}

static int phpc_value_is_null(const __value__ *v)
{
    return PHPC_TYPE_NULL == phpc_value_kind(v);
}

static size_t phpc_string_len(__string__ *s)
{
    if (NULL == s) {
        return 0;
    }

    return (size_t) *((long long *) ((char *) s + sizeof(void *)));
}

static const char *phpc_string_data(__string__ *s)
{
    if (NULL == s) {
        return "";
    }

    return (const char *) s + sizeof(void *) + sizeof(long long);
}

static int phpc_string_spaceship(__string__ *left, __string__ *right)
{
    const char *ldata = phpc_string_data(left);
    const char *rdata = phpc_string_data(right);
    int cmp = strcmp(ldata, rdata);

    if (cmp < 0) {
        return -1;
    }
    if (cmp > 0) {
        return 1;
    }

    return 0;
}

static int phpc_long_spaceship(long long left, long long right)
{
    if (left < right) {
        return -1;
    }
    if (left > right) {
        return 1;
    }

    return 0;
}

static int phpc_double_spaceship(double left, double right)
{
    if (left < right) {
        return -1;
    }
    if (left > right) {
        return 1;
    }

    return 0;
}

long long __value__spaceship(__value__ *left, __value__ *right)
{
    int lkind;
    int rkind;

    if (NULL == left || NULL == right) {
        return 0;
    }

    lkind = phpc_value_kind(left);
    rkind = phpc_value_kind(right);

    if (lkind == rkind) {
        switch (lkind) {
            case PHPC_TYPE_NULL:
                return 0;
            case PHPC_TYPE_NATIVE_BOOL:
                return phpc_long_spaceship(
                    left->value[0] ? 1 : 0,
                    right->value[0] ? 1 : 0
                );
            case PHPC_TYPE_NATIVE_LONG:
                return phpc_long_spaceship(__value__readLong(left), __value__readLong(right));
            case PHPC_TYPE_NATIVE_DOUBLE: {
                double lval;
                double rval;

                memcpy(&lval, left->value, sizeof lval);
                memcpy(&rval, right->value, sizeof rval);

                return phpc_double_spaceship(lval, rval);
            }
            case PHPC_TYPE_STRING:
                return phpc_string_spaceship(__value__readString(left), __value__readString(right));
            case PHPC_TYPE_HASHTABLE:
                return __hashtable__compareSpaceship(
                    __value__readHashtable(left),
                    __value__readHashtable(right)
                );
            default:
                break;
        }
    }

    return phpc_long_spaceship((long long) lkind, (long long) rkind);
}

long long __hashtable__compareSpaceship(__hashtable__ *left, __hashtable__ *right)
{
    size_t index;
    __strkey_node__ *lnode;
    __strkey_node__ *rnode;

    if (NULL == left || NULL == right) {
        return 0;
    }
    if (left->numElements > right->numElements) {
        return 1;
    }
    if (left->numElements < right->numElements) {
        return -1;
    }

    for (index = 0; index < left->nextFreeElement && index < right->nextFreeElement; ++index) {
        __value__ *lval = &left->values[index];
        __value__ *rval = &right->values[index];
        long long cmp;

        if (phpc_value_is_null(lval) && phpc_value_is_null(rval)) {
            continue;
        }
        cmp = __value__spaceship(lval, rval);
        if (0 != cmp) {
            return cmp;
        }
    }

    for (lnode = left->strKeys, rnode = right->strKeys;
         NULL != lnode && NULL != rnode;
         lnode = lnode->next, rnode = rnode->next) {
        long long key_cmp = phpc_string_spaceship(lnode->key, rnode->key);
        long long val_cmp;

        if (0 != key_cmp) {
            return key_cmp;
        }
        val_cmp = __value__spaceship(&lnode->value, &rnode->value);
        if (0 != val_cmp) {
            return val_cmp;
        }
    }

    return 0;
}
