/*
 * phpversion() / php_sapi_name() / php_uname() runtime for VM/JIT/AOT (issue #3174).
 * php-src reference: ext/standard/info.c
 */

#include <stdint.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <sys/utsname.h>

typedef struct __string__ __string__;

extern __string__ *__string__init(long long size, const char *value);

#define PHPC_VERSION "8.2.0-dev"
#define PHPC_SAPI "cli"

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

static __string__ *phpc_string_from_cstr(const char *value)
{
    size_t len;

    if (NULL == value) {
        value = "";
    }
    len = strlen(value);

    return __string__init((long long) len, value);
}

__string__ *__compiler_phpversion(__string__ *extension)
{
    if (NULL == extension || 0 == phpc_strlen(extension)) {
        return phpc_string_from_cstr(PHPC_VERSION);
    }

    return NULL;
}

__string__ *__compiler_php_sapi_name(void)
{
    return phpc_string_from_cstr(PHPC_SAPI);
}

static int phpc_uname_mode_char(__string__ *mode, char *out)
{
    char c = 'a';

    if (NULL != mode && phpc_strlen(mode) >= 1) {
        c = phpc_strdata(mode)[0];
    }
    if (NULL != out) {
        *out = c;
    }

    return (c == 'a' || c == 's' || c == 'n' || c == 'r' || c == 'v' || c == 'm') ? 1 : 0;
}

__string__ *__compiler_php_uname(__string__ *mode)
{
    struct utsname buf;
    char result[512];
    char mode_c = 'a';

    if (!phpc_uname_mode_char(mode, &mode_c)) {
        return phpc_string_from_cstr("");
    }
    if (0 != uname(&buf)) {
        return phpc_string_from_cstr("");
    }

    switch (mode_c) {
    case 's':
        return phpc_string_from_cstr(buf.sysname);
    case 'n':
        return phpc_string_from_cstr(buf.nodename);
    case 'r':
        return phpc_string_from_cstr(buf.release);
    case 'v':
        return phpc_string_from_cstr(buf.version);
    case 'm':
        return phpc_string_from_cstr(buf.machine);
    case 'a':
    default:
        snprintf(
            result,
            sizeof(result),
            "%s %s %s %s %s",
            buf.sysname,
            buf.nodename,
            buf.release,
            buf.version,
            buf.machine
        );
        return phpc_string_from_cstr(result);
    }
}
