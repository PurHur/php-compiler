/*
 * Pending TypeError for JIT/AOT intersection parameter checks (#3077).
 */

#include <string.h>

#define PHPC_JIT_TYPE_ERROR_PENDING_MAX 512

static char phpc_jit_type_error_pending_msg[PHPC_JIT_TYPE_ERROR_PENDING_MAX];
static int phpc_jit_type_error_pending_set;

void phpc_jit_type_error_clear_pending(void)
{
    phpc_jit_type_error_pending_set = 0;
    phpc_jit_type_error_pending_msg[0] = '\0';
}

int phpc_jit_type_error_has_pending(void)
{
    return phpc_jit_type_error_pending_set;
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
}

void __compiler_jit_raise_type_error(const char *msg, unsigned long len)
{
    if (NULL == msg) {
        msg = "TypeError";
        len = strlen(msg);
    }
    if (len >= PHPC_JIT_TYPE_ERROR_PENDING_MAX) {
        len = PHPC_JIT_TYPE_ERROR_PENDING_MAX - 1;
    }
    memcpy(phpc_jit_type_error_pending_msg, msg, len);
    phpc_jit_type_error_pending_msg[len] = '\0';
    phpc_jit_type_error_pending_set = 1;
}
