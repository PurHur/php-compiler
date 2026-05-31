/*
 * str_increment() / str_decrement() runtime for VM/JIT/AOT (issues #3102, #3726).
 * PHP 8.3 ext/standard/string.c — alphanumeric ASCII inc/dec via __string__init.
 */

#include <stddef.h>
#include <stdio.h>
#include <string.h>

typedef struct __string__ __string__;

extern __string__ *__string__init(long long size, const char *value);
extern void __compiler_jit_raise_type_error(const char *msg, unsigned long len);

#define PHPC_STR_INCDEC_BUF 256

static void phpc_str_incdec_raise(const char *msg)
{
    if (NULL == msg) {
        return;
    }
    __compiler_jit_raise_type_error(msg, (unsigned long) strlen(msg));
}

static void phpc_str_incdec_raise_empty(const char *fn)
{
    char buf[PHPC_STR_INCDEC_BUF];
    snprintf(buf, sizeof(buf), "%s(): Argument #1 ($string) must not be empty", fn);
    phpc_str_incdec_raise(buf);
}

static void phpc_str_incdec_raise_non_alnum(const char *fn)
{
    char buf[PHPC_STR_INCDEC_BUF];
    snprintf(
        buf,
        sizeof(buf),
        "%s(): Argument #1 ($string) must be composed only of alphanumeric ASCII characters",
        fn
    );
    phpc_str_incdec_raise(buf);
}

static void phpc_str_incdec_raise_out_of_range(const char *fn, const char *str)
{
    char buf[PHPC_STR_INCDEC_BUF];
    snprintf(
        buf,
        sizeof(buf),
        "%s(): Argument #1 ($string) \"%s\" is out of decrement range",
        fn,
        (NULL != str) ? str : ""
    );
    phpc_str_incdec_raise(buf);
}

static int phpc_only_ascii_alnum(const char *str, size_t len)
{
    if (NULL == str) {
        return 0;
    }
    for (size_t i = 0; i < len; ++i) {
        unsigned char c = (unsigned char) str[i];
        if (!((c >= '0' && c <= '9') || (c >= 'A' && c <= 'Z') || (c >= 'a' && c <= 'z'))) {
            return 0;
        }
    }

    return 1;
}

__string__ *phpc_str_increment(const char *str)
{
    char buf[PHPC_STR_INCDEC_BUF];
    size_t len;
    size_t position;
    int carry;

    if (NULL == str) {
        str = "";
    }
    len = strlen(str);
    if (0 == len) {
        phpc_str_incdec_raise_empty("str_increment");
        return NULL;
    }
    if (len >= sizeof(buf)) {
        phpc_str_incdec_raise_non_alnum("str_increment");
        return NULL;
    }
    if (!phpc_only_ascii_alnum(str, len)) {
        phpc_str_incdec_raise_non_alnum("str_increment");
        return NULL;
    }

    memcpy(buf, str, len + 1);
    position = len - 1;
    carry = 0;

    do {
        char c = buf[position];
        if (c != 'z' && c != 'Z' && c != '9') {
            carry = 0;
            buf[position] = (char) (c + 1);
        } else {
            carry = 1;
            if (c == '9') {
                buf[position] = '0';
            } else {
                buf[position] = (char) (c - 25);
            }
        }
    } while (carry && position-- > 0);

    if (carry) {
        char out[257];
        char prefix = (buf[0] == '0') ? '1' : buf[0];
        out[0] = prefix;
        memcpy(out + 1, buf, len);
        out[len + 1] = '\0';

        return __string__init((long long) (len + 1), out);
    }

    return __string__init((long long) len, buf);
}

__string__ *phpc_str_decrement(const char *str)
{
    char buf[PHPC_STR_INCDEC_BUF];
    size_t len;
    size_t position;
    int carry;

    if (NULL == str) {
        str = "";
    }
    len = strlen(str);
    if (0 == len) {
        phpc_str_incdec_raise_empty("str_decrement");
        return NULL;
    }
    if (len >= sizeof(buf)) {
        phpc_str_incdec_raise_non_alnum("str_decrement");
        return NULL;
    }
    if (!phpc_only_ascii_alnum(str, len)) {
        phpc_str_incdec_raise_non_alnum("str_decrement");
        return NULL;
    }
    if (str[0] == '0') {
        phpc_str_incdec_raise_out_of_range("str_decrement", str);
        return NULL;
    }

    memcpy(buf, str, len + 1);
    position = len - 1;
    carry = 0;

    do {
        char c = buf[position];
        if (c != 'a' && c != 'A' && c != '0') {
            carry = 0;
            buf[position] = (char) (c - 1);
        } else {
            carry = 1;
            if (c == '0') {
                buf[position] = '9';
            } else {
                buf[position] = (char) (c + 25);
            }
        }
    } while (carry && position-- > 0);

    if (carry || (buf[0] == '0' && len > 1)) {
        if (1 == len) {
            phpc_str_incdec_raise_out_of_range("str_decrement", str);
            return NULL;
        }

        return __string__init((long long) (len - 1), buf + 1);
    }

    return __string__init((long long) len, buf);
}
