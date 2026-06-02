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
long long __object__compareSpaceship(__object__ *left, __object__ *right);

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

static void phpc_slot_content_to_value(void *content, __value__ *dest)
{
    __value__ *boxed;

    if (NULL == content) {
        __value__writeNull(dest);

        return;
    }
    if (phpc_slot_is_object(content)) {
        dest->type = PHPC_TYPE_OBJECT;
        memcpy(dest->value, &content, sizeof(void *));

        return;
    }
    boxed = (__value__ *) content;
    memcpy(dest, boxed, sizeof(__value__));
}

static size_t phpc_object_header_bytes(void)
{
    return sizeof(phpc_object_header);
}

#define PHPC_TYPE_NULL 0
#define PHPC_TYPE_NATIVE_LONG 1
#define PHPC_TYPE_NATIVE_BOOL 2
#define PHPC_TYPE_NATIVE_DOUBLE 3
#define PHPC_TYPE_STRING 4
#define PHPC_TYPE_OBJECT 5
#define PHPC_TYPE_HASHTABLE 7

#define PHPC_TYPEINFO_TYPEMASK 0xFFFFFFFC
#define PHPC_TYPEINFO_TYPE_OBJECT 8

typedef void __object__;

typedef struct {
    int32_t refcount;
    int32_t typeinfo;
} phpc_ref_head;

typedef struct {
    phpc_ref_head ref;
    int64_t class_id;
    int8_t constructed;
} phpc_object_header;

extern void __value__writeNull(__value__ *out);
extern __object__ *__value__readObject(__value__ *v);
extern int phpc_object_prop_count(void *obj);

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
            case PHPC_TYPE_OBJECT:
                return __object__compareSpaceship(
                    __value__readObject(left),
                    __value__readObject(right)
                );
            default:
                break;
        }
    }

    return phpc_long_spaceship((long long) lkind, (long long) rkind);
}

long long __object__compareSpaceship(__object__ *left, __object__ *right)
{
    phpc_object_header *lhdr;
    phpc_object_header *rhdr;
    int prop_count;
    int slot;
    size_t header;
    char *lbase;
    char *rbase;
    __value__ lval;
    __value__ rval;

    if (left == right) {
        return 0;
    }
    if (NULL == left || NULL == right) {
        return phpc_long_spaceship(
            left != NULL ? (long long) PHPC_TYPE_OBJECT : (long long) PHPC_TYPE_NULL,
            right != NULL ? (long long) PHPC_TYPE_OBJECT : (long long) PHPC_TYPE_NULL
        );
    }

    lhdr = (phpc_object_header *) left;
    rhdr = (phpc_object_header *) right;
    if (lhdr->class_id != rhdr->class_id) {
        return 1;
    }

    prop_count = phpc_object_prop_count(left);
    header = phpc_object_header_bytes();
    lbase = (char *) left;
    rbase = (char *) right;

    for (slot = 0; slot < prop_count; ++slot) {
        void **lslot_ptr = (void **) (lbase + header + (size_t) slot * sizeof(void *));
        void **rslot_ptr = (void **) (rbase + header + (size_t) slot * sizeof(void *));
        long long cmp;

        phpc_slot_content_to_value(*lslot_ptr, &lval);
        phpc_slot_content_to_value(*rslot_ptr, &rval);
        cmp = __value__spaceship(&lval, &rval);
        if (0 != cmp) {
            return cmp;
        }
    }

    return 0;
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
