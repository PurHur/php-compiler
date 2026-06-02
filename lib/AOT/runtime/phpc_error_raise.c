/*
 * Pending Error for JIT asymmetric visibility and engine checks (#4029).
 *
 * Native JIT records a message and returns; Func\JIT::execute throws catchable Error.
 * Standalone AOT main calls phpc_jit_abort_if_pending_error after user code.
 */

#include <stdio.h>
#include <stdlib.h>
#include <string.h>

#define PHPC_JIT_ERROR_PENDING_MAX 512

static char phpc_jit_error_pending_msg[PHPC_JIT_ERROR_PENDING_MAX];
static int phpc_jit_error_pending_set;

void phpc_jit_error_clear_pending(void)
{
    phpc_jit_error_pending_set = 0;
    phpc_jit_error_pending_msg[0] = '\0';
}

int phpc_jit_error_has_pending(void)
{
    return phpc_jit_error_pending_set;
}

void phpc_jit_error_copy_pending(char *buf, unsigned long bufsize)
{
    if (!phpc_jit_error_pending_set || NULL == buf || 0 == bufsize) {
        if (NULL != buf && bufsize > 0) {
            buf[0] = '\0';
        }
        return;
    }
    if (bufsize > PHPC_JIT_ERROR_PENDING_MAX) {
        bufsize = PHPC_JIT_ERROR_PENDING_MAX;
    }
    memcpy(buf, phpc_jit_error_pending_msg, bufsize - 1);
    buf[bufsize - 1] = '\0';
    phpc_jit_error_pending_set = 0;
}

void __compiler_jit_raise_error(const char *msg, unsigned long len)
{
    if (NULL == msg) {
        msg = "Error";
        len = strlen(msg);
    }
    if (len >= PHPC_JIT_ERROR_PENDING_MAX) {
        len = PHPC_JIT_ERROR_PENDING_MAX - 1;
    }
    memcpy(phpc_jit_error_pending_msg, msg, len);
    phpc_jit_error_pending_msg[len] = '\0';
    phpc_jit_error_pending_set = 1;
}

void phpc_jit_abort_if_pending_error(void)
{
    char buf[PHPC_JIT_ERROR_PENDING_MAX];

    if (!phpc_jit_error_pending_set) {
        return;
    }
    phpc_jit_error_copy_pending(buf, sizeof buf);
    if ('\0' == buf[0]) {
        strncpy(buf, "Error", sizeof buf - 1);
        buf[sizeof buf - 1] = '\0';
    }
    fprintf(
        stderr,
        "PHP Fatal error:  Uncaught Error: %s\n",
        buf
    );
    exit(255);
}
