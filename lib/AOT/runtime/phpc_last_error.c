/*
 * error_get_last() / error_clear_last() runtime for JIT/AOT (issue #3158, #1492).
 *
 * php-src: ext/standard/error.c
 */

#include <stddef.h>
#include <stdlib.h>
#include <string.h>

typedef struct __string__ __string__;
typedef struct __hashtable__ __hashtable__;
typedef struct __value__ __value__;

extern __string__ *__string__init(long long size, const char *value);
extern __hashtable__ *__hashtable__alloc(void);
extern void __hashtable__setStringKeyString(__hashtable__ *ht, __string__ *key, __string__ *val);
extern void __hashtable__setStringKeyLong(__hashtable__ *ht, __string__ *key, long long val);

static int phpc_last_error_active = 0;
static int phpc_last_error_type = 0;
static char *phpc_last_error_message = NULL;
static char *phpc_last_error_file = NULL;
static int phpc_last_error_line = 0;

static __string__ *phpc_cstr_to_string(const char *cstr)
{
    size_t len = 0;
    const char *p;

    if (NULL == cstr) {
        cstr = "";
    }
    p = cstr;
    while ('\0' != *p) {
        ++len;
        ++p;
    }

    return __string__init((long long) len, cstr);
}

static void phpc_last_error_free_message(void)
{
    if (NULL != phpc_last_error_message) {
        free(phpc_last_error_message);
        phpc_last_error_message = NULL;
    }
    if (NULL != phpc_last_error_file) {
        free(phpc_last_error_file);
        phpc_last_error_file = NULL;
    }
}

static char *phpc_last_error_dup(const char *src, size_t len)
{
    char *out;

    if (NULL == src) {
        src = "";
        len = 0;
    }
    out = (char *) malloc(len + 1);
    if (NULL == out) {
        return NULL;
    }
    if (len > 0) {
        memcpy(out, src, len);
    }
    out[len] = '\0';

    return out;
}

void __phpc_last_error_record(int type, const char *msg, size_t msg_len, const char *file, int line)
{
    phpc_last_error_active = 1;
    phpc_last_error_type = type;
    phpc_last_error_line = line;
    phpc_last_error_free_message();
    phpc_last_error_message = phpc_last_error_dup(msg, msg_len);
    phpc_last_error_file = phpc_last_error_dup(file, file != NULL ? strlen(file) : 0);
}

void __phpc_last_error_clear(void)
{
    phpc_last_error_active = 0;
    phpc_last_error_type = 0;
    phpc_last_error_line = 0;
    phpc_last_error_free_message();
}

int __phpc_last_error_is_active(void)
{
    return phpc_last_error_active && NULL != phpc_last_error_message;
}

__hashtable__ *__phpc_last_error_to_hashtable(void)
{
    __hashtable__ *ht;

    if (!phpc_last_error_active || NULL == phpc_last_error_message) {
        return NULL;
    }
    ht = __hashtable__alloc();
    __hashtable__setStringKeyLong(ht, phpc_cstr_to_string("type"), (long long) phpc_last_error_type);
    __hashtable__setStringKeyString(
        ht,
        phpc_cstr_to_string("message"),
        phpc_cstr_to_string(phpc_last_error_message)
    );
    __hashtable__setStringKeyString(
        ht,
        phpc_cstr_to_string("file"),
        phpc_cstr_to_string(NULL != phpc_last_error_file ? phpc_last_error_file : "")
    );
    __hashtable__setStringKeyLong(ht, phpc_cstr_to_string("line"), (long long) phpc_last_error_line);

    return ht;
}
