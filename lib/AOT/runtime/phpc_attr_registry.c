/*
 * PHP 8 attribute name registry for native JIT/AOT reflection (#1936).
 */

#include <stddef.h>
#include <stdlib.h>
#include <string.h>

typedef struct phpc_attr_method_entry {
    const char *method_lc;
    const char *const *attr_names;
    size_t attr_count;
    struct phpc_attr_method_entry *next;
} phpc_attr_method_entry;

typedef struct phpc_attr_class_entry {
    const char *class_lc;
    const char *const *attr_names;
    size_t attr_count;
    phpc_attr_method_entry *methods;
    struct phpc_attr_class_entry *next;
} phpc_attr_class_entry;

static phpc_attr_class_entry *phpc_attr_head = NULL;

static char *phpc_ascii_lower_dup(const char *s)
{
    size_t i;
    size_t len;
    char *out;
    if (NULL == s) {
        return NULL;
    }
    len = strlen(s);
    out = (char *) malloc(len + 1);
    if (NULL == out) {
        return NULL;
    }
    for (i = 0; i < len; ++i) {
        char c = s[i];
        if (c >= 'A' && c <= 'Z') {
            c = (char) (c + 32);
        }
        out[i] = c;
    }
    out[len] = '\0';
    return out;
}

static phpc_attr_class_entry *phpc_attr_find_class_mut(const char *class_lc)
{
    phpc_attr_class_entry *cur = phpc_attr_head;
    while (NULL != cur) {
        if (NULL != class_lc && NULL != cur->class_lc && 0 == strcmp(cur->class_lc, class_lc)) {
            return cur;
        }
        cur = cur->next;
    }
    return NULL;
}

static const phpc_attr_class_entry *phpc_attr_find_class(const char *class_lc)
{
    return phpc_attr_find_class_mut(class_lc);
}

void phpc_attr_register_class_attrs(
    const char *class_lc,
    const char *const *attr_names,
    size_t attr_count
)
{
    phpc_attr_class_entry *node;
    if (NULL == class_lc) {
        return;
    }
    node = phpc_attr_find_class_mut(class_lc);
    if (NULL == node) {
        node = (phpc_attr_class_entry *) calloc(1, sizeof(phpc_attr_class_entry));
        if (NULL == node) {
            return;
        }
        node->class_lc = class_lc;
        node->next = phpc_attr_head;
        phpc_attr_head = node;
    }
    node->attr_names = attr_names;
    node->attr_count = attr_count;
}

void phpc_attr_register_method_attrs(
    const char *class_lc,
    const char *method_lc,
    const char *const *attr_names,
    size_t attr_count
)
{
    phpc_attr_class_entry *cls;
    phpc_attr_method_entry *m;
    if (NULL == class_lc || NULL == method_lc) {
        return;
    }
    cls = phpc_attr_find_class_mut(class_lc);
    if (NULL == cls) {
        cls = (phpc_attr_class_entry *) calloc(1, sizeof(phpc_attr_class_entry));
        if (NULL == cls) {
            return;
        }
        cls->class_lc = class_lc;
        cls->next = phpc_attr_head;
        phpc_attr_head = cls;
    }
    m = (phpc_attr_method_entry *) calloc(1, sizeof(phpc_attr_method_entry));
    if (NULL == m) {
        return;
    }
    m->method_lc = method_lc;
    m->attr_names = attr_names;
    m->attr_count = attr_count;
    m->next = cls->methods;
    cls->methods = m;
}

size_t phpc_attr_class_count(const char *class_lc)
{
    char *tmp = phpc_ascii_lower_dup(class_lc);
    const phpc_attr_class_entry *entry = phpc_attr_find_class(NULL != tmp ? tmp : class_lc);
    if (NULL != tmp) {
        free(tmp);
    }
    if (NULL == entry) {
        return 0;
    }
    return entry->attr_count;
}

const char *phpc_attr_class_name_at(const char *class_lc, size_t idx)
{
    char *tmp = phpc_ascii_lower_dup(class_lc);
    const phpc_attr_class_entry *entry = phpc_attr_find_class(NULL != tmp ? tmp : class_lc);
    if (NULL != tmp) {
        free(tmp);
    }
    if (NULL == entry || NULL == entry->attr_names) {
        return NULL;
    }
    if (idx >= entry->attr_count) {
        return NULL;
    }
    return entry->attr_names[idx];
}

size_t phpc_attr_method_count(const char *class_lc, const char *method_lc)
{
    char *tmpc = phpc_ascii_lower_dup(class_lc);
    char *tmpm = phpc_ascii_lower_dup(method_lc);
    const phpc_attr_class_entry *entry = phpc_attr_find_class(NULL != tmpc ? tmpc : class_lc);
    const phpc_attr_method_entry *m = NULL;
    phpc_attr_method_entry *cur;
    if (NULL != entry) {
        cur = entry->methods;
        while (NULL != cur) {
            if (NULL != cur->method_lc && NULL != tmpm && 0 == strcmp(cur->method_lc, tmpm)) {
                m = cur;
                break;
            }
            if (NULL != cur->method_lc && NULL != method_lc && 0 == strcmp(cur->method_lc, method_lc)) {
                m = cur;
                break;
            }
            cur = cur->next;
        }
    }
    if (NULL != tmpc) {
        free(tmpc);
    }
    if (NULL != tmpm) {
        free(tmpm);
    }
    if (NULL == m) {
        return 0;
    }
    return m->attr_count;
}

const char *phpc_attr_method_name_at(const char *class_lc, const char *method_lc, size_t idx)
{
    char *tmpc = phpc_ascii_lower_dup(class_lc);
    char *tmpm = phpc_ascii_lower_dup(method_lc);
    const phpc_attr_class_entry *entry = phpc_attr_find_class(NULL != tmpc ? tmpc : class_lc);
    const phpc_attr_method_entry *m = NULL;
    phpc_attr_method_entry *cur;
    if (NULL != entry) {
        cur = entry->methods;
        while (NULL != cur) {
            if (NULL != cur->method_lc && NULL != tmpm && 0 == strcmp(cur->method_lc, tmpm)) {
                m = cur;
                break;
            }
            if (NULL != cur->method_lc && NULL != method_lc && 0 == strcmp(cur->method_lc, method_lc)) {
                m = cur;
                break;
            }
            cur = cur->next;
        }
    }
    if (NULL != tmpc) {
        free(tmpc);
    }
    if (NULL != tmpm) {
        free(tmpm);
    }
    if (NULL == m || NULL == m->attr_names) {
        return NULL;
    }
    if (idx >= m->attr_count) {
        return NULL;
    }
    return m->attr_names[idx];
}

/* --- attribute ctor args for ReflectionAttribute::newInstance() (#3206, #4598) --- */

typedef struct __hashtable__ __hashtable__;
typedef struct __string__ __string__;

extern __hashtable__ *__hashtable__alloc(void);
extern void __hashtable__grow(__hashtable__ *ht, size_t n);
extern void __hashtable__setHashtableAt(__hashtable__ *ht, size_t idx, __hashtable__ *val);
extern void __hashtable__setStringKeyString(__hashtable__ *ht, __string__ *key, __string__ *val);
extern void __hashtable__setStringKeyLong(__hashtable__ *ht, __string__ *key, long long val);
extern void __hashtable__setStringKeyDouble(__hashtable__ *ht, __string__ *key, double val);
extern void __hashtable__setStringKeyBool(__hashtable__ *ht, __string__ *key, int val);
extern __string__ *__string__init(long long len, const char *data);

#define PHPC_ATTR_ARG_NULL 0
#define PHPC_ATTR_ARG_BOOL 1
#define PHPC_ATTR_ARG_LONG 2
#define PHPC_ATTR_ARG_DOUBLE 3
#define PHPC_ATTR_ARG_STRING 4

typedef struct phpc_attr_arg_spec {
    const char *arg_name;
    int value_type;
    long long lval;
    double dval;
    const char *sval;
    int bval;
} phpc_attr_arg_spec;

typedef struct phpc_attr_args_list {
    phpc_attr_arg_spec *specs;
    size_t count;
} phpc_attr_args_list;

typedef struct phpc_attr_class_args_node {
    const char *class_lc;
    phpc_attr_args_list *per_attr;
    size_t attr_count;
    struct phpc_attr_class_args_node *next;
} phpc_attr_class_args_node;

static phpc_attr_class_args_node *phpc_attr_args_head = NULL;

static phpc_attr_class_args_node *phpc_attr_find_args_mut(const char *class_lc)
{
    phpc_attr_class_args_node *cur = phpc_attr_args_head;
    while (NULL != cur) {
        if (NULL != class_lc && NULL != cur->class_lc && 0 == strcmp(cur->class_lc, class_lc)) {
            return cur;
        }
        cur = cur->next;
    }
    return NULL;
}

static const phpc_attr_class_args_node *phpc_attr_find_args(const char *class_lc)
{
    return phpc_attr_find_args_mut(class_lc);
}

static __string__ *phpc_attr_cstr_key(const char *s)
{
    size_t len;
    if (NULL == s) {
        s = "";
    }
    len = strlen(s);
    return __string__init((long long) len, s);
}

static void phpc_attr_write_value_key(__hashtable__ *entry, phpc_attr_arg_spec *spec)
{
    __string__ *value_key = phpc_attr_cstr_key("value");
    switch (spec->value_type) {
        case PHPC_ATTR_ARG_BOOL:
            __hashtable__setStringKeyBool(entry, value_key, spec->bval);
            break;
        case PHPC_ATTR_ARG_LONG:
            __hashtable__setStringKeyLong(entry, value_key, spec->lval);
            break;
        case PHPC_ATTR_ARG_DOUBLE:
            __hashtable__setStringKeyDouble(entry, value_key, spec->dval);
            break;
        case PHPC_ATTR_ARG_STRING:
            if (NULL != spec->sval) {
                __string__ *val = __string__init((long long) strlen(spec->sval), spec->sval);
                __hashtable__setStringKeyString(entry, value_key, val);
            }
            break;
        case PHPC_ATTR_ARG_NULL:
        default:
            break;
    }
}

void phpc_attr_register_class_args(
    const char *class_lc,
    phpc_attr_args_list *per_attr,
    size_t attr_count
)
{
    phpc_attr_class_args_node *node;
    if (NULL == class_lc || NULL == per_attr || 0 == attr_count) {
        return;
    }
    node = phpc_attr_find_args_mut(class_lc);
    if (NULL == node) {
        node = (phpc_attr_class_args_node *) calloc(1, sizeof(phpc_attr_class_args_node));
        if (NULL == node) {
            return;
        }
        node->class_lc = class_lc;
        node->next = phpc_attr_args_head;
        phpc_attr_args_head = node;
    }
    node->per_attr = per_attr;
    node->attr_count = attr_count;
}

static char *phpc_strdup(const char *s)
{
    size_t len;
    char *out;
    if (NULL == s) {
        return NULL;
    }
    len = strlen(s);
    out = (char *) malloc(len + 1);
    if (NULL == out) {
        return NULL;
    }
    memcpy(out, s, len);
    out[len] = '\0';
    return out;
}

typedef struct phpc_attr_flat_arg_node {
    char *class_lc;
    size_t attr_idx;
    phpc_attr_arg_spec spec;
    struct phpc_attr_flat_arg_node *next;
} phpc_attr_flat_arg_node;

static phpc_attr_flat_arg_node *phpc_attr_flat_head = NULL;

void phpc_attr_register_class_arg_flat(
    const char *class_lc,
    size_t attr_idx,
    size_t arg_idx,
    const char *arg_name,
    int value_type,
    long long lval,
    double dval,
    const char *sval,
    int bval
)
{
    phpc_attr_flat_arg_node *node;
    char *class_copy;
    char *name_copy;
    char *sval_copy;
    (void) arg_idx;
    if (NULL == class_lc) {
        return;
    }
    class_copy = phpc_ascii_lower_dup(class_lc);
    if (NULL == class_copy) {
        return;
    }
    name_copy = NULL;
    if (NULL != arg_name && arg_name[0] != '\0') {
        name_copy = phpc_strdup(arg_name);
        if (NULL == name_copy) {
            free(class_copy);
            return;
        }
    }
    sval_copy = NULL;
    if (PHPC_ATTR_ARG_STRING == value_type && NULL != sval) {
        sval_copy = phpc_strdup(sval);
        if (NULL == sval_copy) {
            free(class_copy);
            free(name_copy);
            return;
        }
    }
    node = (phpc_attr_flat_arg_node *) calloc(1, sizeof(phpc_attr_flat_arg_node));
    if (NULL == node) {
        free(class_copy);
        free(name_copy);
        free(sval_copy);
        return;
    }
    node->class_lc = class_copy;
    node->attr_idx = attr_idx;
    node->spec.arg_name = name_copy;
    node->spec.value_type = value_type;
    node->spec.lval = lval;
    node->spec.dval = dval;
    node->spec.sval = sval_copy;
    node->spec.bval = bval;
    node->next = phpc_attr_flat_head;
    phpc_attr_flat_head = node;
}

static void phpc_attr_materialize_flat_args(const char *class_lc)
{
    phpc_attr_class_args_node *node;
    phpc_attr_flat_arg_node *cur;
    size_t max_attr = 0;
    size_t ai;
    if (NULL == phpc_attr_find_args(class_lc)) {
        for (cur = phpc_attr_flat_head; NULL != cur; cur = cur->next) {
            if (0 == strcmp(cur->class_lc, class_lc) && cur->attr_idx + 1 > max_attr) {
                max_attr = cur->attr_idx + 1;
            }
        }
        if (0 == max_attr) {
            return;
        }
        node = (phpc_attr_class_args_node *) calloc(1, sizeof(phpc_attr_class_args_node));
        if (NULL == node) {
            return;
        }
        node->class_lc = phpc_strdup(class_lc);
        if (NULL == node->class_lc) {
            free(node);
            return;
        }
        node->attr_count = max_attr;
        node->per_attr = (phpc_attr_args_list *) calloc(max_attr, sizeof(phpc_attr_args_list));
        if (NULL == node->per_attr) {
            free(node);
            return;
        }
        for (cur = phpc_attr_flat_head; NULL != cur; cur = cur->next) {
            phpc_attr_args_list *list;
            phpc_attr_arg_spec *specs;
            size_t new_count;
            if (0 != strcmp(cur->class_lc, class_lc) || cur->attr_idx >= max_attr) {
                continue;
            }
            list = &node->per_attr[cur->attr_idx];
            new_count = list->count + 1;
            specs = (phpc_attr_arg_spec *) realloc(list->specs, new_count * sizeof(phpc_attr_arg_spec));
            if (NULL == specs) {
                continue;
            }
            specs[list->count] = cur->spec;
            list->specs = specs;
            list->count = new_count;
        }
        node->next = phpc_attr_args_head;
        phpc_attr_args_head = node;
    }
}

__hashtable__ *phpc_attr_class_args_hashtable(const char *class_lc, size_t attr_idx)
{
    char *tmp;
    const phpc_attr_class_args_node *node;
    phpc_attr_args_list *list;
    __hashtable__ *out;
    size_t ai;
    if (NULL == class_lc) {
        return NULL;
    }
    tmp = phpc_ascii_lower_dup(class_lc);
    node = phpc_attr_find_args(NULL != tmp ? tmp : class_lc);
    if (NULL != tmp) {
        free(tmp);
    }
    if (NULL == node) {
        char *class_key = phpc_ascii_lower_dup(class_lc);
        if (NULL != class_key) {
            phpc_attr_materialize_flat_args(class_key);
            node = phpc_attr_find_args(class_key);
            free(class_key);
        }
    }
    if (NULL == node || NULL == node->per_attr || attr_idx >= node->attr_count) {
        return NULL;
    }
    list = &node->per_attr[attr_idx];
    if (NULL == list->specs || 0 == list->count) {
        return NULL;
    }
    out = __hashtable__alloc();
    __hashtable__grow(out, list->count);
    for (ai = 0; ai < list->count; ++ai) {
        phpc_attr_arg_spec *spec = &list->specs[ai];
        __hashtable__ *entry = __hashtable__alloc();
        __hashtable__grow(entry, 2);
        if (NULL != spec->arg_name && spec->arg_name[0] != '\0') {
            __string__ *name_key = phpc_attr_cstr_key("name");
            __string__ *name_val = __string__init((long long) strlen(spec->arg_name), spec->arg_name);
            __hashtable__setStringKeyString(entry, name_key, name_val);
        }
        phpc_attr_write_value_key(entry, spec);
        __hashtable__setHashtableAt(out, ai, entry);
    }
    return out;
}
