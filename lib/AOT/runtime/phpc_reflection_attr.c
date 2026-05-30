/*
 * Native reflection object side-tables for JIT/AOT attribute reflection (#1936).
 */

#include <stddef.h>
#include <stdlib.h>
#include <string.h>

typedef struct __object__ __object__;

typedef struct phpc_reflect_class_node {
    const void *obj;
    const char *class_name;
    size_t class_len;
    struct phpc_reflect_class_node *next;
} phpc_reflect_class_node;

typedef struct phpc_reflect_method_node {
    const void *obj;
    const char *class_name;
    size_t class_len;
    const char *method_name;
    size_t method_len;
    struct phpc_reflect_method_node *next;
} phpc_reflect_method_node;

typedef struct phpc_reflect_attr_node {
    const void *obj;
    const char *attr_name;
    size_t attr_len;
    struct phpc_reflect_attr_node *next;
} phpc_reflect_attr_node;

static phpc_reflect_class_node *phpc_reflect_class_head = NULL;
static phpc_reflect_method_node *phpc_reflect_method_head = NULL;
static phpc_reflect_attr_node *phpc_reflect_attr_head = NULL;

static char *phpc_copy_bytes(const char *name, size_t len)
{
    char *copy;
    if (NULL == name) {
        return NULL;
    }
    copy = (char *) malloc(len + 1);
    if (NULL == copy) {
        return NULL;
    }
    memcpy(copy, name, len);
    copy[len] = '\0';
    return copy;
}

void phpc_reflect_set_class(const void *obj, const char *name, size_t len)
{
    phpc_reflect_class_node *n;
    char *copy;
    if (NULL == obj) {
        return;
    }
    n = (phpc_reflect_class_node *) calloc(1, sizeof(phpc_reflect_class_node));
    if (NULL == n) {
        return;
    }
    copy = phpc_copy_bytes(name, len);
    n->obj = obj;
    n->class_name = NULL != copy ? copy : name;
    n->class_len = len;
    n->next = phpc_reflect_class_head;
    phpc_reflect_class_head = n;
}

const char *phpc_reflect_get_class_name(const void *obj, size_t *out_len)
{
    phpc_reflect_class_node *n = phpc_reflect_class_head;
    while (NULL != n) {
        if (n->obj == obj) {
            if (NULL != out_len) {
                *out_len = n->class_len;
            }
            return n->class_name;
        }
        n = n->next;
    }
    if (NULL != out_len) {
        *out_len = 0;
    }
    return NULL;
}

void phpc_reflect_set_method(
    const void *obj,
    const char *class_name,
    size_t class_len,
    const char *method_name,
    size_t method_len
)
{
    phpc_reflect_method_node *n;
    char *classCopy;
    char *methodCopy;
    if (NULL == obj) {
        return;
    }
    n = (phpc_reflect_method_node *) calloc(1, sizeof(phpc_reflect_method_node));
    if (NULL == n) {
        return;
    }
    classCopy = phpc_copy_bytes(class_name, class_len);
    methodCopy = phpc_copy_bytes(method_name, method_len);
    n->obj = obj;
    n->class_name = NULL != classCopy ? classCopy : class_name;
    n->class_len = class_len;
    n->method_name = NULL != methodCopy ? methodCopy : method_name;
    n->method_len = method_len;
    n->next = phpc_reflect_method_head;
    phpc_reflect_method_head = n;
}

const char *phpc_reflect_get_method_class(const void *obj, size_t *out_len)
{
    phpc_reflect_method_node *n = phpc_reflect_method_head;
    while (NULL != n) {
        if (n->obj == obj) {
            if (NULL != out_len) {
                *out_len = n->class_len;
            }
            return n->class_name;
        }
        n = n->next;
    }
    if (NULL != out_len) {
        *out_len = 0;
    }
    return NULL;
}

const char *phpc_reflect_get_method_name(const void *obj, size_t *out_len)
{
    phpc_reflect_method_node *n = phpc_reflect_method_head;
    while (NULL != n) {
        if (n->obj == obj) {
            if (NULL != out_len) {
                *out_len = n->method_len;
            }
            return n->method_name;
        }
        n = n->next;
    }
    if (NULL != out_len) {
        *out_len = 0;
    }
    return NULL;
}

void phpc_reflect_set_attr_name(const void *obj, const char *name, size_t len)
{
    phpc_reflect_attr_node *n;
    char *copy;
    if (NULL == obj) {
        return;
    }
    n = (phpc_reflect_attr_node *) calloc(1, sizeof(phpc_reflect_attr_node));
    if (NULL == n) {
        return;
    }
    copy = phpc_copy_bytes(name, len);
    n->obj = obj;
    n->attr_name = NULL != copy ? copy : name;
    n->attr_len = len;
    n->next = phpc_reflect_attr_head;
    phpc_reflect_attr_head = n;
}

const char *phpc_reflect_get_attr_name(const void *obj, size_t *out_len)
{
    phpc_reflect_attr_node *n = phpc_reflect_attr_head;
    while (NULL != n) {
        if (n->obj == obj) {
            if (NULL != out_len) {
                *out_len = n->attr_len;
            }
            return n->attr_name;
        }
        n = n->next;
    }
    if (NULL != out_len) {
        *out_len = 0;
    }
    return NULL;
}
