/*
 * Spaceship (<=>) for boxed values and native hashtables (issue #3672).
 *
 * @see Zend/zend_operators.c compare_function(), zend_compare_arrays()
 */

#include <math.h>
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

#define PHPC_TYPE_NULL 0
#define PHPC_TYPE_NATIVE_LONG 1
#define PHPC_TYPE_NATIVE_BOOL 2
#define PHPC_TYPE_NATIVE_DOUBLE 3
#define PHPC_TYPE_STRING 4
#define PHPC_TYPE_OBJECT 5
#define PHPC_TYPE_HASHTABLE 7

#define PHPC_TYPEINFO_TYPEMASK 0xFFFFFFFC
#define PHPC_TYPEINFO_TYPE_STRING 4
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

extern long long __value__readLong(__value__ *v);
extern __string__ *__value__readString(__value__ *v);
extern __hashtable__ *__value__readHashtable(__value__ *v);
extern void __value__writeNull(__value__ *out);
extern __object__ *__value__readObject(__value__ *v);
extern int phpc_object_prop_count(void *obj);

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
    if (isnan(left) || isnan(right)) {
        return 1;
    }
    if (left < right) {
        return -1;
    }
    if (left > right) {
        return 1;
    }

    return 0;
}

static int phpc_string_to_bool(__string__ *s)
{
    size_t len = phpc_string_len(s);

    if (0 == len) {
        return 0;
    }
    if (1 == len && '0' == phpc_string_data(s)[0]) {
        return 0;
    }

    return 1;
}

static int phpc_string_is_numeric(__string__ *s)
{
    const char *data = phpc_string_data(s);
    size_t len = phpc_string_len(s);
    char *end = NULL;
    double d;

    if (0 == len) {
        return 0;
    }
    d = strtod(data, &end);
    if (end == data) {
        return 0;
    }

    return (size_t) (end - data) == len;
}

static double phpc_string_to_numeric(__string__ *s)
{
    if (!phpc_string_is_numeric(s)) {
        return 0.0;
    }

    return strtod(phpc_string_data(s), NULL);
}

/** Zend compare_function number↔string path (#4681). */
static int phpc_spaceship_number_string(double num, __string__ *str, int num_on_left)
{
    size_t len = phpc_string_len(str);

    if (0 == len) {
        return num_on_left ? 1 : -1;
    }
    if (phpc_string_is_numeric(str)) {
        double rhs = phpc_string_to_numeric(str);
        int cmp = phpc_double_spaceship(num, rhs);

        return num_on_left ? cmp : -cmp;
    }

    return num_on_left ? -1 : 1;
}

static int phpc_slot_points_to_string(void *slot)
{
    phpc_ref_head *head;

    if (NULL == slot) {
        return 0;
    }
    head = (phpc_ref_head *) slot;

    return (head->typeinfo & PHPC_TYPEINFO_TYPEMASK) == PHPC_TYPEINFO_TYPE_STRING;
}

static __string__ *phpc_object_case_name_slot(__object__ *obj, int slot_index)
{
    size_t header = phpc_object_header_bytes();
    char *base = (char *) obj;
    void **slot_ptr = (void **) (base + header + (size_t) slot_index * sizeof(void *));

    if (NULL == *slot_ptr || !phpc_slot_points_to_string(*slot_ptr)) {
        return NULL;
    }

    return (__string__ *) *slot_ptr;
}

/** Zend zend_compare_enum() — returns -1 if operands are not enum-case layout (#4805). */
static int phpc_try_enum_case_spaceship(__object__ *left, __object__ *right)
{
    phpc_object_header *lhdr;
    phpc_object_header *rhdr;
    int lprops;
    int rprops;
    __string__ *lname;
    __string__ *rname;
    const char *ldata;
    const char *rdata;
    size_t llen;
    size_t rlen;

    if (left == right) {
        return 0;
    }
    if (NULL == left || NULL == right) {
        return -1;
    }

    lprops = phpc_object_prop_count(left);
    rprops = phpc_object_prop_count(right);
    if (lprops != rprops || (lprops != 0 && lprops != 2)) {
        return -1;
    }

    lname = phpc_object_case_name_slot(left, 0);
    rname = phpc_object_case_name_slot(right, 0);
    if (NULL == lname || NULL == rname) {
        return -1;
    }

    lhdr = (phpc_object_header *) left;
    rhdr = (phpc_object_header *) right;
    if (lhdr->class_id != rhdr->class_id) {
        return 1;
    }

    ldata = phpc_string_data(lname);
    rdata = phpc_string_data(rname);
    llen = phpc_string_len(lname);
    rlen = phpc_string_len(rname);
    if (llen != rlen) {
        return 1;
    }
    if (0 == strncasecmp(ldata, rdata, llen)) {
        return 0;
    }

    return 1;
}

static int phpc_spaceship_mixed(__value__ *left, __value__ *right)
{
    int lkind = phpc_value_kind(left);
    int rkind = phpc_value_kind(right);

    if (PHPC_TYPE_NATIVE_BOOL == lkind && PHPC_TYPE_STRING == rkind) {
        int lbool = left->value[0] ? 1 : 0;

        return phpc_long_spaceship(lbool, phpc_string_to_bool(__value__readString(right)));
    }
    if (PHPC_TYPE_STRING == lkind && PHPC_TYPE_NATIVE_BOOL == rkind) {
        int rbool = right->value[0] ? 1 : 0;

        return phpc_long_spaceship(phpc_string_to_bool(__value__readString(left)), rbool);
    }
    if (PHPC_TYPE_NULL == lkind && PHPC_TYPE_STRING == rkind) {
        __string__ *str = __value__readString(right);

        if (0 == phpc_string_len(str)) {
            return 0;
        }

        return phpc_spaceship_number_string(0.0, str, 1);
    }
    if (PHPC_TYPE_STRING == lkind && PHPC_TYPE_NULL == rkind) {
        __string__ *str = __value__readString(left);

        if (0 == phpc_string_len(str)) {
            return 0;
        }

        return -phpc_spaceship_number_string(0.0, str, 1);
    }
    if (PHPC_TYPE_NATIVE_LONG == lkind && PHPC_TYPE_STRING == rkind) {
        return phpc_spaceship_number_string((double) __value__readLong(left), __value__readString(right), 1);
    }
    if (PHPC_TYPE_STRING == lkind && PHPC_TYPE_NATIVE_LONG == rkind) {
        return -phpc_spaceship_number_string((double) __value__readLong(right), __value__readString(left), 1);
    }
    if (PHPC_TYPE_NATIVE_DOUBLE == lkind && PHPC_TYPE_STRING == rkind) {
        double lval;

        memcpy(&lval, left->value, sizeof lval);

        return phpc_spaceship_number_string(lval, __value__readString(right), 1);
    }
    if (PHPC_TYPE_STRING == lkind && PHPC_TYPE_NATIVE_DOUBLE == rkind) {
        double rval;

        memcpy(&rval, right->value, sizeof rval);

        return -phpc_spaceship_number_string(rval, __value__readString(left), 1);
    }
    if (PHPC_TYPE_NATIVE_BOOL == lkind
        && (PHPC_TYPE_NATIVE_LONG == rkind || PHPC_TYPE_NATIVE_DOUBLE == rkind || PHPC_TYPE_NULL == rkind)
    ) {
        int lbool = left->value[0] ? 1 : 0;
        double rnum = PHPC_TYPE_NULL == rkind ? 0.0
            : (PHPC_TYPE_NATIVE_LONG == rkind ? (double) __value__readLong(right) : 0.0);

        if (PHPC_TYPE_NATIVE_DOUBLE == rkind) {
            memcpy(&rnum, right->value, sizeof rnum);
        }

        return phpc_long_spaceship(lbool, (long long) rnum);
    }
    if (PHPC_TYPE_NATIVE_BOOL == rkind
        && (PHPC_TYPE_NATIVE_LONG == lkind || PHPC_TYPE_NATIVE_DOUBLE == lkind || PHPC_TYPE_NULL == lkind)
    ) {
        int rbool = right->value[0] ? 1 : 0;
        double lnum = PHPC_TYPE_NULL == lkind ? 0.0
            : (PHPC_TYPE_NATIVE_LONG == lkind ? (double) __value__readLong(left) : 0.0);

        if (PHPC_TYPE_NATIVE_DOUBLE == lkind) {
            memcpy(&lnum, left->value, sizeof lnum);
        }

        return phpc_long_spaceship((long long) lnum, rbool);
    }
    if (PHPC_TYPE_NULL == lkind
        && (PHPC_TYPE_NATIVE_LONG == rkind || PHPC_TYPE_NATIVE_DOUBLE == rkind)
    ) {
        double rnum = PHPC_TYPE_NATIVE_LONG == rkind
            ? (double) __value__readLong(right) : 0.0;

        if (PHPC_TYPE_NATIVE_DOUBLE == rkind) {
            memcpy(&rnum, right->value, sizeof rnum);
        }

        return phpc_double_spaceship(0.0, rnum);
    }
    if (PHPC_TYPE_NULL == rkind
        && (PHPC_TYPE_NATIVE_LONG == lkind || PHPC_TYPE_NATIVE_DOUBLE == lkind)
    ) {
        double lnum = PHPC_TYPE_NATIVE_LONG == lkind
            ? (double) __value__readLong(left) : 0.0;

        if (PHPC_TYPE_NATIVE_DOUBLE == lkind) {
            memcpy(&lnum, left->value, sizeof lnum);
        }

        return phpc_double_spaceship(lnum, 0.0);
    }
    if (PHPC_TYPE_NATIVE_LONG == lkind && PHPC_TYPE_NATIVE_DOUBLE == rkind) {
        double rval;

        memcpy(&rval, right->value, sizeof rval);

        return phpc_double_spaceship((double) __value__readLong(left), rval);
    }
    if (PHPC_TYPE_NATIVE_DOUBLE == lkind && PHPC_TYPE_NATIVE_LONG == rkind) {
        double lval;

        memcpy(&lval, left->value, sizeof lval);

        return phpc_double_spaceship(lval, (double) __value__readLong(right));
    }

    if (PHPC_TYPE_OBJECT == lkind && PHPC_TYPE_STRING == rkind) {
        if (NULL != phpc_object_case_name_slot(__value__readObject(left), 0)) {
            return 1;
        }
    }
    if (PHPC_TYPE_STRING == lkind && PHPC_TYPE_OBJECT == rkind) {
        if (NULL != phpc_object_case_name_slot(__value__readObject(right), 0)) {
            return 1;
        }
    }

    return phpc_long_spaceship((long long) lkind, (long long) rkind);
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

    return phpc_spaceship_mixed(left, right);
}

long long __object__compareSpaceship(__object__ *left, __object__ *right)
{
    int enum_cmp;
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

    enum_cmp = phpc_try_enum_case_spaceship(left, right);
    if (enum_cmp >= 0) {
        return (long long) enum_cmp;
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
