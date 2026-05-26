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
