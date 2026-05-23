/*
 * getenv() helper for standalone AOT (issue #1068).
 * __compiler_getenv fills a __value__ out-parameter (string or boolean false).
 */

#include <stdlib.h>
#include <string.h>

typedef struct __string__ __string__;

typedef struct {
    signed char type;
    unsigned char value[8];
} __value__;

extern __string__ *__string__init(long long size, const char *value);
extern void __value__writeString(__value__ *out, __string__ *str);

#define PHPC_TYPE_NATIVE_BOOL 2

static size_t ge_strlen(__string__ *s)
{
    if (NULL == s) {
        return 0;
    }

    return (size_t) *((long long *) ((char *) s + sizeof(void *)));
}

static const char *ge_strdata(__string__ *s)
{
    if (NULL == s) {
        return "";
    }

    return (const char *) s + sizeof(void *) + sizeof(long long);
}

void __compiler_getenv(__string__ *name, __value__ *out)
{
    size_t name_len;
    char *name_buf;
    const char *env;

    if (NULL == name || NULL == out) {
        return;
    }
    name_len = ge_strlen(name);
    name_buf = (char *) malloc(name_len + 1U);
    if (NULL == name_buf) {
        out->type = PHPC_TYPE_NATIVE_BOOL;
        out->value[0] = 0;

        return;
    }
    memcpy(name_buf, ge_strdata(name), name_len);
    name_buf[name_len] = '\0';
    env = getenv(name_buf);
    free(name_buf);
    if (NULL == env) {
        out->type = PHPC_TYPE_NATIVE_BOOL;
        out->value[0] = 0;

        return;
    }
    __value__writeString(out, __string__init((long long) strlen(env), env));
}
