/*
 * ini_set() runtime for AOT/JIT (issue #1374).
 * Supported keys: error_reporting, display_errors, memory_limit (rejects "-1").
 */

#include <stdio.h>
#include <stdlib.h>
#include <string.h>

typedef struct __string__ __string__;
typedef struct __value__ {
    char type;
    char value[8];
} __value__;

extern __string__ *__string__init(long long size, const char *value);
extern void __value__writeString(__value__ *out, __string__ *str);
extern void __value__writeLong(__value__ *out, long long v);

#define PHPC_TYPE_BOOL 2

static size_t ini_strlen(__string__ *s)
{
    if (NULL == s) {
        return 0;
    }

    return (size_t) *((long long *) ((char *) s + sizeof(void *)));
}

static const char *ini_strdata(__string__ *s)
{
    if (NULL == s) {
        return "";
    }

    return (const char *) s + sizeof(void *) + sizeof(long long);
}

static int phpc_ini_error_reporting = 32767;
static int phpc_ini_display_errors = 1;
static char phpc_ini_memory_limit[64] = "128M";

/** Nesting depth for `@` (issue #3546 / #4070). */
static int phpc_silence_depth = 0;
static int phpc_silence_saved_error_reporting = 0;

static void ini_write_bool_false(__value__ *out)
{
    if (NULL == out) {
        return;
    }
    out->type = PHPC_TYPE_BOOL;
    out->value[0] = 0;
}

static void ini_write_cstr(__value__ *out, const char *value)
{
    if (NULL == out || NULL == value) {
        return;
    }
    __value__writeString(out, __string__init((long long) strlen(value), value));
}

static int ini_parse_bool(const char *value, size_t len)
{
    if (NULL == value || 0 == len) {
        return 0;
    }
    if (1 == len && '0' == value[0]) {
        return 0;
    }
    if (1 == len && '1' == value[0]) {
        return 1;
    }
    if (len >= 3 && 0 == strncasecmp(value, "off", 3) && 3 == len) {
        return 0;
    }
    if (len >= 2 && 0 == strncasecmp(value, "on", 2) && 2 == len) {
        return 1;
    }
    if (len >= 5 && 0 == strncasecmp(value, "false", 5) && 5 == len) {
        return 0;
    }
    if (len >= 4 && 0 == strncasecmp(value, "true", 4) && 4 == len) {
        return 1;
    }

    return 1;
}

static char *ini_copy_cstr(__string__ *str, size_t *out_len)
{
    size_t len;
    const char *bytes;
    char *buf;

    if (NULL == str) {
        *out_len = 0;

        return NULL;
    }
    len = ini_strlen(str);
    bytes = ini_strdata(str);
    buf = (char *) malloc(len + 1);
    if (NULL == buf) {
        *out_len = 0;

        return NULL;
    }
    memcpy(buf, bytes, len);
    buf[len] = '\0';
    *out_len = len;

    return buf;
}

void __compiler_ini_get(__string__ *option, __value__ *out)
{
    char *opt;
    size_t opt_len;
    char buf[64];

    if (NULL == out) {
        return;
    }
    opt = ini_copy_cstr(option, &opt_len);
    if (NULL == opt) {
        ini_write_bool_false(out);

        return;
    }

    if (0 == strcasecmp(opt, "error_reporting")) {
        snprintf(buf, sizeof(buf), "%d", phpc_ini_error_reporting);
        ini_write_cstr(out, buf);
    } else if (0 == strcasecmp(opt, "display_errors")) {
        snprintf(buf, sizeof(buf), "%d", phpc_ini_display_errors ? 1 : 0);
        ini_write_cstr(out, buf);
    } else if (0 == strcasecmp(opt, "memory_limit")) {
        ini_write_cstr(out, phpc_ini_memory_limit);
    } else {
        ini_write_bool_false(out);
    }

    free(opt);
}

void __compiler_error_reporting(int has_new_level, long long new_level, __value__ *out)
{
    long long old = phpc_ini_error_reporting;

    if (has_new_level) {
        phpc_ini_error_reporting = (int) new_level;
    }
    if (NULL != out) {
        __value__writeLong(out, old);
    }
}

/** Non-zero when errno should be emitted (Zend EG(error_reporting) & level). */
int __compiler_phpc_error_level_enabled(int level)
{
    return (phpc_ini_error_reporting & level) != 0;
}

void __compiler_begin_silence(void)
{
    if (0 == phpc_silence_depth) {
        phpc_silence_saved_error_reporting = phpc_ini_error_reporting;
        phpc_ini_error_reporting = 0;
    }
    ++phpc_silence_depth;
}

void __compiler_end_silence(void)
{
    if (phpc_silence_depth <= 0) {
        return;
    }
    --phpc_silence_depth;
    if (0 == phpc_silence_depth) {
        phpc_ini_error_reporting = phpc_silence_saved_error_reporting;
    }
}

void __compiler_ini_set(__string__ *option, __string__ *new_value, __value__ *out)
{
    char *opt;
    char *val;
    size_t opt_len;
    size_t val_len;
    char old_buf[64];

    if (NULL == out) {
        return;
    }
    opt = ini_copy_cstr(option, &opt_len);
    val = ini_copy_cstr(new_value, &val_len);
    if (NULL == opt || NULL == val) {
        free(opt);
        free(val);
        ini_write_bool_false(out);

        return;
    }

    if (0 == strcasecmp(opt, "error_reporting")) {
        snprintf(old_buf, sizeof(old_buf), "%d", phpc_ini_error_reporting);
        phpc_ini_error_reporting = (int) strtol(val, NULL, 10);
        ini_write_cstr(out, old_buf);
    } else if (0 == strcasecmp(opt, "display_errors")) {
        snprintf(old_buf, sizeof(old_buf), "%d", phpc_ini_display_errors ? 1 : 0);
        phpc_ini_display_errors = ini_parse_bool(val, val_len);
        ini_write_cstr(out, old_buf);
    } else if (0 == strcasecmp(opt, "memory_limit")) {
        if (0 == strcmp(val, "-1")) {
            ini_write_bool_false(out);
        } else {
            ini_write_cstr(out, phpc_ini_memory_limit);
            strncpy(phpc_ini_memory_limit, val, sizeof(phpc_ini_memory_limit) - 1);
            phpc_ini_memory_limit[sizeof(phpc_ini_memory_limit) - 1] = '\0';
        }
    } else {
        ini_write_bool_false(out);
    }

    free(opt);
    free(val);
}
