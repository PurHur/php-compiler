/*
 * Process helpers for AOT/JIT (inventory blocker batch 2).
 */

#include <stdio.h>
#include <stdlib.h>
#include <string.h>

typedef struct __string__ __string__;
extern __string__ *__string__init(long long size, const char *value);

static const char *phpc_strdata(__string__ *s)
{
    if (NULL == s) {
        return "";
    }
    return (const char *) s + sizeof(void *) + sizeof(long long);
}

__string__ *__compiler_shell_exec(__string__ *cmd)
{
    const char *command;
    FILE *fp;
    char chunk[4096];
    char *buf;
    size_t len;
    size_t cap;
    char *grown;
    __string__ *result;

    if (NULL == cmd) {
        return NULL;
    }
    command = phpc_strdata(cmd);
    if ('\0' == *command) {
        return NULL;
    }
    fp = popen(command, "r");
    if (NULL == fp) {
        return NULL;
    }
    cap = 4096;
    len = 0;
    buf = (char *) malloc(cap);
    if (NULL == buf) {
        pclose(fp);
        return NULL;
    }
    while (NULL != fgets(chunk, (int) sizeof(chunk), fp)) {
        size_t chunk_len = strlen(chunk);
        if (len + chunk_len + 1 > cap) {
            cap = (len + chunk_len + 1) * 2;
            grown = (char *) realloc(buf, cap);
            if (NULL == grown) {
                free(buf);
                pclose(fp);
                return NULL;
            }
            buf = grown;
        }
        memcpy(buf + len, chunk, chunk_len);
        len += chunk_len;
    }
    if (-1 == pclose(fp) && 0 == len) {
        free(buf);
        return NULL;
    }
    result = __string__init((long long) len, buf);
    free(buf);
    return result;
}
