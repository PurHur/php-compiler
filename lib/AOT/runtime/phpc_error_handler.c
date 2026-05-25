/*
 * set_error_handler() / restore_error_handler() stack for JIT/AOT (issue #1379, #1492).
 */

#include <stdio.h>
#include <stdlib.h>
#include <string.h>

typedef struct __value__ {
    char type;
    char value[8];
} __value__;

extern void __value__writeString(__value__ *out, void *str);
extern void *__string__init(long long size, const char *value);

#define PHPC_TYPE_BOOL 2
#define PHPC_ERR_HANDLER_MAX 32

typedef int (*phpc_error_handler_fn_t)(int errno, const char *msg, size_t msg_len, int line);

typedef struct {
    char *name;
    phpc_error_handler_fn_t fn;
    int mask;
} phpc_error_handler_entry;

static phpc_error_handler_entry phpc_error_handler_stack[PHPC_ERR_HANDLER_MAX];
static int phpc_error_handler_depth = 0;

static void err_write_bool(__value__ *out, int value)
{
    if (NULL == out) {
        return;
    }
    out->type = PHPC_TYPE_BOOL;
    out->value[0] = value ? 1 : 0;
}

static void err_write_null(__value__ *out)
{
    if (NULL == out) {
        return;
    }
    out->type = 0;
    out->value[0] = 0;
}

static void err_write_name(__value__ *out, const char *name)
{
    if (NULL == out) {
        return;
    }
    if (NULL == name) {
        err_write_null(out);
        return;
    }
    __value__writeString(out, __string__init((long long) strlen(name), name));
}

static void err_free_entry(phpc_error_handler_entry *entry)
{
    if (NULL == entry) {
        return;
    }
    if (NULL != entry->name) {
        free(entry->name);
        entry->name = NULL;
    }
    entry->fn = NULL;
    entry->mask = 0;
}

int __phpc_error_handler_dispatch(int errno, const char *msg, size_t msg_len, int line)
{
    if (phpc_error_handler_depth <= 0) {
        return 0;
    }
    phpc_error_handler_entry *top = &phpc_error_handler_stack[phpc_error_handler_depth - 1];
    if (NULL == top->fn) {
        return 0;
    }
    if (0 == (top->mask & errno)) {
        return 0;
    }
    if (NULL == msg) {
        msg = "";
        msg_len = 0;
    }

    return 0 != top->fn(errno, msg, msg_len, line);
}

void __phpc_error_handler_set_apply(
    __value__ *out,
    const char *name,
    size_t name_len,
    void *fn_opaque,
    int mask
)
{
    phpc_error_handler_fn_t fn = (phpc_error_handler_fn_t) fn_opaque;

    const char *prev_name = NULL;
    if (phpc_error_handler_depth > 0) {
        prev_name = phpc_error_handler_stack[phpc_error_handler_depth - 1].name;
    }
    err_write_name(out, prev_name);

    if (phpc_error_handler_depth >= PHPC_ERR_HANDLER_MAX) {
        return;
    }

    phpc_error_handler_entry *entry = &phpc_error_handler_stack[phpc_error_handler_depth];
    entry->fn = fn;
    entry->mask = mask;
    entry->name = NULL;
    if (NULL != name && name_len > 0) {
        entry->name = (char *) malloc(name_len + 1);
        if (NULL != entry->name) {
            memcpy(entry->name, name, name_len);
            entry->name[name_len] = '\0';
        }
    }
    phpc_error_handler_depth++;
}

void __phpc_error_handler_restore_apply(__value__ *out)
{
    if (phpc_error_handler_depth <= 0) {
        err_write_bool(out, 0);
        return;
    }
    phpc_error_handler_depth--;
    err_free_entry(&phpc_error_handler_stack[phpc_error_handler_depth]);
    err_write_bool(out, 1);
}
