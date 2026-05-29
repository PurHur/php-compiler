/*
 * User class method registry for native get_class_methods() (#3118).
 *
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(get_class_methods)
 */

#include <stddef.h>
#include <stdlib.h>
#include <string.h>

typedef struct __hashtable__ __hashtable__;
typedef struct __string__ __string__;
typedef struct __value__ __value__;

extern __hashtable__ *__hashtable__alloc(void);
extern void __hashtable__setStringAt(__hashtable__ *ht, size_t index, __string__ *val);
extern __string__ *__string__init(long long size, const char *value);
extern void __value__writeHashtable(__value__ *out, __hashtable__ *ht);
extern void __value__writeBool(__value__ *out, int v);

typedef struct phpc_cm_method {
    const char *method_lc;
    const char *display_name;
    int visibility;
    struct phpc_cm_method *next;
} phpc_cm_method;

typedef struct phpc_cm_class {
    const char *class_lc;
    const char *parent_lc;
    phpc_cm_method *methods;
    struct phpc_cm_class *next;
} phpc_cm_class;

static phpc_cm_class *phpc_cm_head = NULL;

static phpc_cm_class *phpc_cm_find_mut(const char *class_lc)
{
    phpc_cm_class *cur = phpc_cm_head;
    while (NULL != cur) {
        if (NULL != class_lc && NULL != cur->class_lc && 0 == strcmp(cur->class_lc, class_lc)) {
            return cur;
        }
        cur = cur->next;
    }
    return NULL;
}

static phpc_cm_class *phpc_cm_ensure(const char *class_lc)
{
    phpc_cm_class *node = phpc_cm_find_mut(class_lc);
    if (NULL != node) {
        return node;
    }
    node = (phpc_cm_class *) calloc(1, sizeof(phpc_cm_class));
    if (NULL == node) {
        return NULL;
    }
    node->class_lc = class_lc;
    node->next = phpc_cm_head;
    phpc_cm_head = node;
    return node;
}

void phpc_class_methods_set_parent(const char *class_lc, const char *parent_lc)
{
    phpc_cm_class *node;
    if (NULL == class_lc) {
        return;
    }
    node = phpc_cm_ensure(class_lc);
    if (NULL != node) {
        node->parent_lc = parent_lc;
    }
}

void phpc_class_methods_register(
    const char *class_lc,
    const char *method_lc,
    const char *display_name,
    int visibility
)
{
    phpc_cm_class *cls;
    phpc_cm_method *m;
    if (NULL == class_lc || NULL == method_lc || NULL == display_name) {
        return;
    }
    cls = phpc_cm_ensure(class_lc);
    if (NULL == cls) {
        return;
    }
    for (m = cls->methods; NULL != m; m = m->next) {
        if (NULL != m->method_lc && 0 == strcmp(m->method_lc, method_lc)) {
            m->display_name = display_name;
            m->visibility = visibility;
            return;
        }
    }
    m = (phpc_cm_method *) calloc(1, sizeof(phpc_cm_method));
    if (NULL == m) {
        return;
    }
    m->method_lc = method_lc;
    m->display_name = display_name;
    m->visibility = visibility;
    m->next = cls->methods;
    cls->methods = m;
}

typedef struct phpc_cm_seen {
    const char *method_lc;
    struct phpc_cm_seen *next;
} phpc_cm_seen;

static int phpc_cm_seen_contains(phpc_cm_seen *head, const char *method_lc)
{
    while (NULL != head) {
        if (NULL != head->method_lc && 0 == strcmp(head->method_lc, method_lc)) {
            return 1;
        }
        head = head->next;
    }
    return 0;
}

static void phpc_cm_collect(
    const char *class_lc,
    int filter,
    phpc_cm_seen **seen,
    __hashtable__ *ht,
    size_t *out_index
)
{
    phpc_cm_class *cls;
    phpc_cm_method *m;
    if (NULL == class_lc) {
        return;
    }
    cls = phpc_cm_find_mut(class_lc);
    if (NULL != cls && NULL != cls->parent_lc) {
        phpc_cm_collect(cls->parent_lc, filter, seen, ht, out_index);
    }
    if (NULL == cls) {
        return;
    }
    for (m = cls->methods; NULL != m; m = m->next) {
        phpc_cm_seen *node;
        if (phpc_cm_seen_contains(*seen, m->method_lc)) {
            continue;
        }
        if (0 != (filter & 7) && 0 == (m->visibility & filter & 7)) {
            continue;
        }
        node = (phpc_cm_seen *) calloc(1, sizeof(phpc_cm_seen));
        if (NULL != node) {
            node->method_lc = m->method_lc;
            node->next = *seen;
            *seen = node;
        }
        __hashtable__setStringAt(
            ht,
            *out_index,
            __string__init((long long) strlen(m->display_name), m->display_name)
        );
        (*out_index)++;
    }
}

void phpc_get_class_methods(const char *class_lc, int filter, __value__ *out)
{
    __hashtable__ *ht;
    phpc_cm_seen *seen = NULL;
    phpc_cm_seen *cur;
    phpc_cm_seen *next;
    size_t index = 0;
    phpc_cm_class *cls;

    if (NULL == out) {
        return;
    }
    if (NULL == class_lc) {
        __value__writeBool(out, 0);
        return;
    }
    cls = phpc_cm_find_mut(class_lc);
    if (NULL == cls) {
        __value__writeBool(out, 0);
        return;
    }
    ht = __hashtable__alloc();
    if (NULL == ht) {
        __value__writeBool(out, 0);
        return;
    }
    phpc_cm_collect(class_lc, filter, &seen, ht, &index);
    for (cur = seen; NULL != cur; cur = next) {
        next = cur->next;
        free(cur);
    }
    __value__writeHashtable(out, ht);
}