/*
 * json_decode() runtime for AOT/JIT (assoc arrays; mirrors superglobals JSON subset).
 */

#include <stdlib.h>
#include <stdint.h>
#include <string.h>

typedef struct __hashtable__ __hashtable__;
typedef struct __string__ __string__;
typedef struct __value__ __value__;

extern __hashtable__ *__hashtable__alloc(void);
extern void __hashtable__setStringKeyString(__hashtable__ *ht, __string__ *key, __string__ *val);
extern void __hashtable__setStringKeyLong(__hashtable__ *ht, __string__ *key, long long val);
extern void __hashtable__setStringKeyBool(__hashtable__ *ht, __string__ *key, int val);
extern void __hashtable__setStringKeyHashtable(__hashtable__ *ht, __string__ *key, __hashtable__ *child);
extern void __hashtable__setStringAt(__hashtable__ *ht, size_t index, __string__ *val);
extern void __hashtable__setLongAt(__hashtable__ *ht, size_t index, long long val);
extern void __hashtable__setBoolAt(__hashtable__ *ht, size_t index, int val);
extern void __hashtable__setDoubleAt(__hashtable__ *ht, size_t index, double val);
extern __hashtable__ *__hashtable__readStringKeyHashtable(__hashtable__ *ht, __string__ *key);
extern __string__ *__string__init(long long size, const char *value);
extern void __value__writeNull(__value__ *out);
extern void __value__writeLong(__value__ *out, long long v);
extern void __value__writeDouble(__value__ *out, double v);
extern void __value__writeString(__value__ *out, __string__ *str);
extern void __value__writeHashtable(__value__ *out, __hashtable__ *ht);

#define PHPC_JSON_MAX_DEPTH 32
#define PHPC_JSON_MAX_LEN (8 * 1024 * 1024)

/* PHP JSON_ERROR_* subset; updated by __compiler_json_decode (issue #1173). */
#define PHPC_JSON_ERROR_NONE 0
#define PHPC_JSON_ERROR_DEPTH 1
#define PHPC_JSON_ERROR_SYNTAX 4

static int phpc_json_last_error = PHPC_JSON_ERROR_NONE;

typedef struct {
    const char *pos;
    const char *end;
    int depth;
    int max_depth;
} phpc_json_ctx;

static __string__ *cstr_to_string(const char *cstr)
{
    return __string__init((long long) strlen(cstr), cstr);
}

static size_t phpc_string_len(__string__ *s)
{
    if (NULL == s) {
        return 0;
    }

    return (size_t) *((long long *) ((char *) s + sizeof(void *)));
}

static const char *phpc_string_data(__string__ *s)
{
    if (NULL == s) {
        return "";
    }

    return (const char *) s + sizeof(void *) + sizeof(long long);
}

static __hashtable__ *phpc_ensure_child(__hashtable__ *ht, const char *key)
{
    __string__ *k = cstr_to_string(key);
    __hashtable__ *child = __hashtable__readStringKeyHashtable(ht, k);

    if (NULL != child) {
        return child;
    }
    child = __hashtable__alloc();
    __hashtable__setStringKeyHashtable(ht, k, child);

    return child;
}

static void phpc_json_skip_ws(phpc_json_ctx *ctx)
{
    while (ctx->pos < ctx->end) {
        char c = *ctx->pos;

        if (' ' != c && '\t' != c && '\n' != c && '\r' != c) {
            break;
        }
        ctx->pos++;
    }
}

static int phpc_json_expect(phpc_json_ctx *ctx, char ch)
{
    phpc_json_skip_ws(ctx);
    if (ctx->pos >= ctx->end || *ctx->pos != ch) {
        return 0;
    }
    ctx->pos++;

    return 1;
}

static int phpc_json_parse_string(phpc_json_ctx *ctx, char *out, size_t out_len)
{
    size_t o = 0;

    if (!phpc_json_expect(ctx, '"')) {
        return 0;
    }
    while (ctx->pos < ctx->end) {
        char c = *ctx->pos++;

        if ('"' == c) {
            out[o] = '\0';

            return 1;
        }
        if ('\\' == c && ctx->pos < ctx->end) {
            char esc = *ctx->pos++;

            if ('"' == esc || '\\' == esc || '/' == esc) {
                c = esc;
            } else if ('b' == esc) {
                c = '\b';
            } else if ('f' == esc) {
                c = '\f';
            } else if ('n' == esc) {
                c = '\n';
            } else if ('r' == esc) {
                c = '\r';
            } else if ('t' == esc) {
                c = '\t';
            } else if ('u' == esc) {
                if (ctx->pos + 4 > ctx->end) {
                    return 0;
                }
                ctx->pos += 4;
                continue;
            } else {
                return 0;
            }
        }
        if (o + 1 >= out_len) {
            return 0;
        }
        out[o++] = c;
    }

    return 0;
}

static int phpc_json_has_fraction(const char *s)
{
    return NULL != strchr(s, '.') || NULL != strchr(s, 'e') || NULL != strchr(s, 'E');
}

static int phpc_json_parse_number(phpc_json_ctx *ctx, char *out, size_t out_len)
{
    const char *start = ctx->pos;
    size_t len;

    if (ctx->pos >= ctx->end) {
        return 0;
    }
    if ('-' == *ctx->pos) {
        ctx->pos++;
    }
    if (ctx->pos >= ctx->end || (*ctx->pos < '0' || *ctx->pos > '9')) {
        return 0;
    }
    while (ctx->pos < ctx->end && *ctx->pos >= '0' && *ctx->pos <= '9') {
        ctx->pos++;
    }
    if (ctx->pos < ctx->end && '.' == *ctx->pos) {
        ctx->pos++;
        while (ctx->pos < ctx->end && *ctx->pos >= '0' && *ctx->pos <= '9') {
            ctx->pos++;
        }
    }
    if (ctx->pos < ctx->end && ('e' == *ctx->pos || 'E' == *ctx->pos)) {
        ctx->pos++;
        if (ctx->pos < ctx->end && ('+' == *ctx->pos || '-' == *ctx->pos)) {
            ctx->pos++;
        }
        while (ctx->pos < ctx->end && *ctx->pos >= '0' && *ctx->pos <= '9') {
            ctx->pos++;
        }
    }
    len = (size_t) (ctx->pos - start);
    if (len + 1 > out_len) {
        return 0;
    }
    memcpy(out, start, len);
    out[len] = '\0';

    return 1;
}

static int phpc_json_parse_literal(phpc_json_ctx *ctx, const char *lit, char *out, size_t out_len)
{
    size_t len = strlen(lit);

    if (ctx->pos + len > ctx->end || 0 != strncmp(ctx->pos, lit, len)) {
        return 0;
    }
    ctx->pos += len;
    strncpy(out, lit, out_len - 1);
    out[out_len - 1] = '\0';

    return 1;
}

static int phpc_json_parse_value(
    phpc_json_ctx *ctx,
    __hashtable__ *ht,
    const char *key,
    int use_index,
    size_t index
);

static int phpc_json_parse_array(phpc_json_ctx *ctx, __hashtable__ *ht, const char *key);

static int phpc_json_store_string(
    __hashtable__ *ht,
    const char *key,
    int use_index,
    size_t index,
    const char *value
)
{
    if (use_index) {
        __hashtable__setStringAt(ht, index, cstr_to_string(value));

        return 1;
    }
    __hashtable__setStringKeyString(ht, cstr_to_string(key), cstr_to_string(value));

    return 1;
}

static int phpc_json_store_long(
    __hashtable__ *ht,
    const char *key,
    int use_index,
    size_t index,
    long long value
)
{
    if (use_index) {
        __hashtable__setLongAt(ht, index, value);

        return 1;
    }
    __hashtable__setStringKeyLong(ht, cstr_to_string(key), value);

    return 1;
}

static int phpc_json_store_bool(
    __hashtable__ *ht,
    const char *key,
    int use_index,
    size_t index,
    int value
)
{
    if (use_index) {
        __hashtable__setBoolAt(ht, index, value);

        return 1;
    }
    __hashtable__setStringKeyBool(ht, cstr_to_string(key), value);

    return 1;
}

static int phpc_json_parse_object(phpc_json_ctx *ctx, __hashtable__ *ht)
{
    char key_buf[256];

    if (!phpc_json_expect(ctx, '{')) {
        return 0;
    }
    phpc_json_skip_ws(ctx);
    if (phpc_json_expect(ctx, '}')) {
        return 1;
    }
    for (;;) {
        if (!phpc_json_parse_string(ctx, key_buf, sizeof(key_buf)) || '\0' == key_buf[0]) {
            return 0;
        }
        if (!phpc_json_expect(ctx, ':')) {
            return 0;
        }
        if (!phpc_json_parse_value(ctx, ht, key_buf, 0, 0)) {
            return 0;
        }
        phpc_json_skip_ws(ctx);
        if (ctx->pos >= ctx->end) {
            return 0;
        }
        if ('}' == *ctx->pos) {
            ctx->pos++;

            return 1;
        }
        if (',' != *ctx->pos) {
            return 0;
        }
        ctx->pos++;
    }
}

static int phpc_json_parse_array(phpc_json_ctx *ctx, __hashtable__ *ht, const char *key)
{
    size_t idx = 0;
    __hashtable__ *list_ht;

    if (!phpc_json_expect(ctx, '[')) {
        return 0;
    }
    phpc_json_skip_ws(ctx);
    if (phpc_json_expect(ctx, ']')) {
        if (NULL != key) {
            list_ht = phpc_ensure_child(ht, key);
            (void) list_ht;
        }

        return 1;
    }
    if (NULL != key) {
        list_ht = phpc_ensure_child(ht, key);
    } else {
        list_ht = ht;
    }
    for (;;) {
        if (!phpc_json_parse_value(ctx, list_ht, NULL, 1, idx)) {
            return 0;
        }
        idx++;
        phpc_json_skip_ws(ctx);
        if (ctx->pos >= ctx->end) {
            return 0;
        }
        if (']' == *ctx->pos) {
            ctx->pos++;

            return 1;
        }
        if (',' != *ctx->pos) {
            return 0;
        }
        ctx->pos++;
    }
}

static int phpc_json_parse_value(
    phpc_json_ctx *ctx,
    __hashtable__ *ht,
    const char *key,
    int use_index,
    size_t index
)
{
    char val_buf[4096];

    if (ctx->depth > ctx->max_depth) {
        phpc_json_last_error = PHPC_JSON_ERROR_DEPTH;

        return 0;
    }
    phpc_json_skip_ws(ctx);
    if (ctx->pos >= ctx->end) {
        return 0;
    }
    if ('"' == *ctx->pos) {
        if (!phpc_json_parse_string(ctx, val_buf, sizeof(val_buf))) {
            return 0;
        }

        return phpc_json_store_string(ht, key, use_index, index, val_buf);
    }
    if ('{' == *ctx->pos) {
        __hashtable__ *child;

        if (use_index || NULL == key) {
            return 0;
        }
        ctx->depth++;
        child = phpc_ensure_child(ht, key);
        if (!phpc_json_parse_object(ctx, child)) {
            ctx->depth--;

            return 0;
        }
        ctx->depth--;

        return 1;
    }
    if ('[' == *ctx->pos) {
        if (use_index || NULL == key) {
            return 0;
        }
        ctx->depth++;
        if (!phpc_json_parse_array(ctx, ht, key)) {
            ctx->depth--;

            return 0;
        }
        ctx->depth--;

        return 1;
    }
    if ('-' == *ctx->pos || (*ctx->pos >= '0' && *ctx->pos <= '9')) {
        if (!phpc_json_parse_number(ctx, val_buf, sizeof(val_buf))) {
            return 0;
        }
        if (phpc_json_has_fraction(val_buf)) {
            if (use_index) {
                __hashtable__setDoubleAt(ht, index, strtod(val_buf, NULL));

                return 1;
            }

            return phpc_json_store_string(ht, key, use_index, index, val_buf);
        }

        return phpc_json_store_long(ht, key, use_index, index, strtoll(val_buf, NULL, 10));
    }
    if ('t' == *ctx->pos) {
        if (!phpc_json_parse_literal(ctx, "true", val_buf, sizeof(val_buf))) {
            return 0;
        }

        return phpc_json_store_bool(ht, key, use_index, index, 1);
    }
    if ('f' == *ctx->pos) {
        if (!phpc_json_parse_literal(ctx, "false", val_buf, sizeof(val_buf))) {
            return 0;
        }

        return phpc_json_store_bool(ht, key, use_index, index, 0);
    }
    if ('n' == *ctx->pos) {
        if (!phpc_json_parse_literal(ctx, "null", val_buf, sizeof(val_buf))) {
            return 0;
        }
        if (use_index) {
            __hashtable__setStringAt(ht, index, cstr_to_string(""));

            return 1;
        }
        __hashtable__setStringKeyString(ht, cstr_to_string(key), cstr_to_string(""));

        return 1;
    }

    return 0;
}

static int phpc_json_parse_top(phpc_json_ctx *ctx, __value__ *out)
{
    char val_buf[4096];

    phpc_json_skip_ws(ctx);
    if (ctx->pos >= ctx->end) {
        return 0;
    }
    if ('{' == *ctx->pos) {
        __hashtable__ *ht = __hashtable__alloc();

        if (!phpc_json_parse_object(ctx, ht)) {
            return 0;
        }
        __value__writeHashtable(out, ht);

        return 1;
    }
    if ('[' == *ctx->pos) {
        __hashtable__ *ht = __hashtable__alloc();

        if (!phpc_json_parse_array(ctx, ht, NULL)) {
            return 0;
        }
        __value__writeHashtable(out, ht);

        return 1;
    }
    if ('"' == *ctx->pos) {
        if (!phpc_json_parse_string(ctx, val_buf, sizeof(val_buf))) {
            return 0;
        }
        __value__writeString(out, cstr_to_string(val_buf));

        return 1;
    }
    if ('-' == *ctx->pos || (*ctx->pos >= '0' && *ctx->pos <= '9')) {
        if (!phpc_json_parse_number(ctx, val_buf, sizeof(val_buf))) {
            return 0;
        }
        if (phpc_json_has_fraction(val_buf)) {
            __value__writeDouble(out, strtod(val_buf, NULL));

            return 1;
        }
        __value__writeLong(out, strtoll(val_buf, NULL, 10));

        return 1;
    }
    if ('t' == *ctx->pos) {
        if (!phpc_json_parse_literal(ctx, "true", val_buf, sizeof(val_buf))) {
            return 0;
        }
        __value__writeLong(out, 1);

        return 1;
    }
    if ('f' == *ctx->pos) {
        if (!phpc_json_parse_literal(ctx, "false", val_buf, sizeof(val_buf))) {
            return 0;
        }
        __value__writeLong(out, 0);

        return 1;
    }
    if ('n' == *ctx->pos) {
        if (!phpc_json_parse_literal(ctx, "null", val_buf, sizeof(val_buf))) {
            return 0;
        }
        __value__writeNull(out);

        return 1;
    }

    return 0;
}

static const char *phpc_json_error_msg(int code)
{
    switch (code) {
    case PHPC_JSON_ERROR_NONE:
        return "No error";
    case PHPC_JSON_ERROR_DEPTH:
        return "Maximum stack depth exceeded";
    case PHPC_JSON_ERROR_SYNTAX:
        return "Syntax error";
    default:
        return "Unknown error";
    }
}

int64_t __compiler_json_last_error(void)
{
    return (int64_t) phpc_json_last_error;
}

__string__ *__compiler_json_last_error_msg(void)
{
    const char *msg = phpc_json_error_msg(phpc_json_last_error);
    size_t len = 0;

    while (msg[len] != '\0') {
        len++;
    }

    return __string__init((long long) len, msg);
}

void __compiler_json_decode(__string__ *json, __value__ *out)
{
    phpc_json_ctx ctx;
    const char *body;
    size_t len;

    phpc_json_last_error = PHPC_JSON_ERROR_NONE;
    __value__writeNull(out);
    if (NULL == json) {
        phpc_json_last_error = PHPC_JSON_ERROR_SYNTAX;

        return;
    }
    body = phpc_string_data(json);
    len = phpc_string_len(json);
    if (0 == len || len > PHPC_JSON_MAX_LEN) {
        phpc_json_last_error = PHPC_JSON_ERROR_SYNTAX;

        return;
    }
    ctx.pos = body;
    ctx.end = body + len;
    ctx.depth = 0;
    ctx.max_depth = PHPC_JSON_MAX_DEPTH;
    if (!phpc_json_parse_top(&ctx, out)) {
        phpc_json_last_error = PHPC_JSON_ERROR_SYNTAX;
        __value__writeNull(out);
    }
}

/*
 * json_validate() — syntax check without returning a PHP value (issue #3101).
 * Returns 1 when valid, 0 on syntax error, -1 when nesting exceeds max_depth.
 */
int64_t __compiler_json_validate(__string__ *json, int64_t max_depth)
{
    phpc_json_ctx ctx;
    unsigned char out_storage[128];
    __value__ *out = (__value__ *) out_storage;
    const char *body;
    size_t len;
    int saved_error;

    phpc_json_last_error = PHPC_JSON_ERROR_NONE;
    if (NULL == json) {
        return 0;
    }
    if (max_depth < 1) {
        return 0;
    }
    body = phpc_string_data(json);
    len = phpc_string_len(json);
    if (0 == len || len > PHPC_JSON_MAX_LEN) {
        return 0;
    }
    memset(out_storage, 0, sizeof(out_storage));
    __value__writeNull(out);
    ctx.pos = body;
    ctx.end = body + len;
    ctx.depth = 0;
    ctx.max_depth = (int) max_depth;
    saved_error = phpc_json_last_error;
    if (!phpc_json_parse_top(&ctx, out)) {
        if (PHPC_JSON_ERROR_DEPTH == phpc_json_last_error) {
            return -1;
        }

        return 0;
    }
    phpc_json_skip_ws(&ctx);
    if (ctx.pos != ctx.end) {
        return 0;
    }
    phpc_json_last_error = saved_error;

    return 1;
}
