/*
 * Pending TypeError / ArgumentCountError for JIT/AOT checks (#3077, #4034).
 */

#include <stdio.h>
#include <stdlib.h>
#include <string.h>

#define PHPC_JIT_TYPE_ERROR_PENDING_MAX 512
#define PHPC_PENDING_TYPE_ERROR 1
#define PHPC_PENDING_ARGUMENT_COUNT_ERROR 2

static char phpc_jit_type_error_pending_msg[PHPC_JIT_TYPE_ERROR_PENDING_MAX];
static int phpc_jit_type_error_pending_set;
static int phpc_jit_type_error_pending_kind;

static void phpc_jit_store_pending_error(int kind, const char *msg, unsigned long len)
{
    if (NULL == msg) {
        msg = "Error";
        len = strlen(msg);
    }
    if (len >= PHPC_JIT_TYPE_ERROR_PENDING_MAX) {
        len = PHPC_JIT_TYPE_ERROR_PENDING_MAX - 1;
    }
    memcpy(phpc_jit_type_error_pending_msg, msg, len);
    phpc_jit_type_error_pending_msg[len] = '\0';
    phpc_jit_type_error_pending_set = 1;
    phpc_jit_type_error_pending_kind = kind;
}

void phpc_jit_type_error_clear_pending(void)
{
    phpc_jit_type_error_pending_set = 0;
    phpc_jit_type_error_pending_kind = 0;
    phpc_jit_type_error_pending_msg[0] = '\0';
}

int phpc_jit_type_error_has_pending(void)
{
    return phpc_jit_type_error_pending_set;
}

int phpc_jit_type_error_pending_kind_get(void)
{
    return phpc_jit_type_error_pending_kind;
}

void phpc_jit_type_error_copy_pending(char *buf, unsigned long bufsize)
{
    if (!phpc_jit_type_error_pending_set || NULL == buf || 0 == bufsize) {
        if (NULL != buf && bufsize > 0) {
            buf[0] = '\0';
        }
        return;
    }
    if (bufsize > PHPC_JIT_TYPE_ERROR_PENDING_MAX) {
        bufsize = PHPC_JIT_TYPE_ERROR_PENDING_MAX;
    }
    memcpy(buf, phpc_jit_type_error_pending_msg, bufsize - 1);
    buf[bufsize - 1] = '\0';
    phpc_jit_type_error_pending_set = 0;
    phpc_jit_type_error_pending_kind = 0;
}

void __compiler_jit_raise_type_error(const char *msg, unsigned long len)
{
    phpc_jit_store_pending_error(PHPC_PENDING_TYPE_ERROR, msg, len);
}

void __compiler_jit_raise_argument_count_error(const char *msg, unsigned long len)
{
    phpc_jit_store_pending_error(PHPC_PENDING_ARGUMENT_COUNT_ERROR, msg, len);
}

/** Standalone AOT: fatal when a builtin left a pending TypeError/ArgumentCountError (#4034). */
void phpc_jit_abort_if_pending_type_error(void)
{
    char buf[PHPC_JIT_TYPE_ERROR_PENDING_MAX];
    const char *class_name;
    int kind;

    if (!phpc_jit_type_error_pending_set) {
        return;
    }
    kind = phpc_jit_type_error_pending_kind;
    phpc_jit_copy_pending_exception(buf, sizeof buf);
    if ('\0' == buf[0]) {
        strncpy(buf, "Error", sizeof buf - 1);
        buf[sizeof buf - 1] = '\0';
    }
    class_name = (PHPC_PENDING_ARGUMENT_COUNT_ERROR == kind)
        ? "ArgumentCountError"
        : "TypeError";
    fprintf(
        stderr,
        "PHP Fatal error:  Uncaught %s: %s\n",
        class_name,
        buf
    );
    exit(255);
}
