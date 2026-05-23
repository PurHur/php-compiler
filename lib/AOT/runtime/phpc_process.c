/*
 * Process helpers for AOT/JIT (inventory blocker batch 2).
 */

#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <sys/random.h>
#include <unistd.h>

typedef struct __string__ __string__;
extern __string__ *__string__init(long long size, const char *value);

static size_t phpc_strlen(__string__ *s)
{
    if (NULL == s) {
        return 0;
    }

    return (size_t) *((long long *) ((char *) s + sizeof(void *)));
}

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

static unsigned long long phpc_random_u64(void)
{
    unsigned long long v = 0;
    ssize_t n;

    n = getrandom(&v, sizeof(v), 0);
    if (n != (ssize_t) sizeof(v)) {
        _exit(1);
    }

    return v;
}

/** str_shuffle() runtime — Fisher–Yates on a copy of the input bytes. */
__string__ *__compiler_str_shuffle(__string__ *in)
{
    size_t len;
    char *buf;
    __string__ *out;
    size_t i;

    if (NULL == in) {
        return __string__init(0, "");
    }
    len = phpc_strlen(in);
    if (len < 2) {
        return __string__init((long long) len, phpc_strdata(in));
    }
    buf = (char *) malloc(len);
    if (NULL == buf) {
        _exit(1);
    }
    memcpy(buf, phpc_strdata(in), len);
    for (i = len; i > 1;) {
        size_t j;
        char tmp;

        --i;
        j = (size_t) (phpc_random_u64() % (unsigned long long) (i + 1));
        tmp = buf[i];
        buf[i] = buf[j];
        buf[j] = tmp;
    }
    out = __string__init((long long) len, buf);
    free(buf);

    return out;
}
