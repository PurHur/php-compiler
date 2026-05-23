/*
 * Runtime CGI superglobal refresh for AOT binaries (issue #201).
 * Linked with LLVM object code; reads getenv and repopulates sg_* globals.
 */

#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <unistd.h>

#if defined(__APPLE__) || defined(__FreeBSD__)
#include <crt_externs.h>
#define phpc_environ (*_NSGetEnviron())
#else
extern char **environ;
#define phpc_environ environ
#endif

typedef struct __hashtable__ __hashtable__;
typedef struct __string__ __string__;

extern __hashtable__ *__hashtable__alloc(void);
extern void __hashtable__setStringKeyString(__hashtable__ *ht, __string__ *key, __string__ *val);
extern void __hashtable__setStringKeyHashtable(__hashtable__ *ht, __string__ *key, __hashtable__ *child);
extern void __hashtable__setStringAt(__hashtable__ *ht, size_t index, __string__ *val);
extern size_t __hashtable__getNumElements(__hashtable__ *ht);
extern __hashtable__ *__hashtable__readStringKeyHashtable(__hashtable__ *ht, __string__ *key);
extern __string__ *__string__init(long long size, const char *value);

extern __hashtable__ *sg_GET;
extern __hashtable__ *sg_POST;
extern __hashtable__ *sg_SERVER;
extern __hashtable__ *sg_REQUEST;
extern __hashtable__ *sg_COOKIE;
extern __hashtable__ *sg_ENV;
extern __hashtable__ *sg_FILES;
extern __hashtable__ *sg_SESSION;

static __string__ *cstr_to_string(const char *cstr)
{
    size_t len = strlen(cstr);

    return __string__init((long long) len, cstr);
}

static void set_string_key(__hashtable__ *ht, const char *key, const char *value)
{
    __string__ *k = cstr_to_string(key);
    __string__ *v = cstr_to_string(value);

    __hashtable__setStringKeyString(ht, k, v);
}

#define SG_MAX_KEY_PARTS 16

typedef struct {
    char *parts[SG_MAX_KEY_PARTS];
    size_t count;
    int append_list;
} sg_parsed_key;

static int sg_is_hex(char c)
{
    return (c >= '0' && c <= '9') || (c >= 'a' && c <= 'f') || (c >= 'A' && c <= 'F');
}

static int sg_hex_value(char c)
{
    if (c >= '0' && c <= '9') {
        return c - '0';
    }
    if (c >= 'a' && c <= 'f') {
        return c - 'a' + 10;
    }

    return c - 'A' + 10;
}

static void sg_url_decode_inplace(char *s)
{
    char *w = s;

    for (char *r = s; '\0' != *r; r++) {
        if ('+' == *r) {
            *w++ = ' ';
        } else if ('%' == *r && sg_is_hex(r[1]) && sg_is_hex(r[2])) {
            *w++ = (char) (sg_hex_value(r[1]) * 16 + sg_hex_value(r[2]));
            r += 2;
        } else {
            *w++ = *r;
        }
    }
    *w = '\0';
}

static void sg_free_parsed_key(sg_parsed_key *pk)
{
    size_t i;

    for (i = 0; i < pk->count; i++) {
        free(pk->parts[i]);
        pk->parts[i] = NULL;
    }
    pk->count = 0;
    pk->append_list = 0;
}

static int sg_parse_key_brackets(const char *raw, sg_parsed_key *out)
{
    const char *p = raw;
    size_t base_len;

    out->count = 0;
    out->append_list = 0;
    if ('\0' == raw[0]) {
        return -1;
    }

    base_len = strcspn(p, "[");
    if (base_len > 0) {
        out->parts[out->count] = strndup(p, base_len);
        if (NULL == out->parts[out->count]) {
            return -1;
        }
        out->count++;
        p += base_len;
    }

    while ('[' == *p) {
        p++;
        if (']' == *p) {
            out->append_list = 1;
            p++;
            break;
        }
        {
            const char *close = strchr(p, ']');
            size_t len;

            if (NULL == close) {
                return -1;
            }
            len = (size_t) (close - p);
            out->parts[out->count] = malloc(len + 1);
            if (NULL == out->parts[out->count]) {
                return -1;
            }
            memcpy(out->parts[out->count], p, len);
            out->parts[out->count][len] = '\0';
            out->count++;
            p = close + 1;
        }
        if ('[' == *p && ']' == p[1]) {
            out->append_list = 1;
            p += 2;
        }
    }

    if ('\0' != *p || 0 == out->count) {
        return -1;
    }

    return 0;
}

static __hashtable__ *sg_ensure_child(__hashtable__ *ht, const char *key)
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

static void sg_set_nested_value(__hashtable__ *root, sg_parsed_key *pk, const char *value)
{
    __hashtable__ *ht = root;
    size_t last;
    const char *leaf;

    if (0 == pk->count) {
        return;
    }
    last = pk->count;
    {
        size_t i;

        for (i = 0; i + 1 < last; i++) {
            ht = sg_ensure_child(ht, pk->parts[i]);
        }
    }
    leaf = pk->parts[last - 1];
    if (pk->append_list) {
        __hashtable__ *list_ht = sg_ensure_child(ht, leaf);
        size_t idx = __hashtable__getNumElements(list_ht);

        __hashtable__setStringAt(list_ht, idx, cstr_to_string(value));

        return;
    }
    set_string_key(ht, leaf, value);
}

static void trim_ws_inplace(char *s)
{
    char *start = s;
    char *end;

    while (' ' == *start || '\t' == *start || '\r' == *start || '\n' == *start) {
        start++;
    }
    if (start != s) {
        memmove(s, start, strlen(start) + 1);
    }
    end = s + strlen(s);
    while (end > s && (' ' == end[-1] || '\t' == end[-1] || '\r' == end[-1] || '\n' == end[-1])) {
        end--;
    }
    *end = '\0';
}

static void parse_delimited_pairs(__hashtable__ *ht, const char *body, char delimiter, int decode_pair_first)
{
    char *copy;
    char *pair;
    char *saveptr;
    char delim[2];

    if (NULL == body || '\0' == body[0]) {
        return;
    }

    copy = strdup(body);
    if (NULL == copy) {
        return;
    }

    delim[0] = delimiter;
    delim[1] = '\0';
    pair = strtok_r(copy, delim, &saveptr);
    while (NULL != pair) {
        char *eq;
        char *raw_key;
        char *raw_val;
        sg_parsed_key pk;

        if (decode_pair_first) {
            trim_ws_inplace(pair);
            sg_url_decode_inplace(pair);
        }
        eq = strchr(pair, '=');
        if (NULL != eq) {
            *eq = '\0';
            raw_key = pair;
            raw_val = eq + 1;
        } else {
            raw_key = pair;
            /* NUL terminator in strdup copy (not a string literal) for __string__init */
            raw_val = pair + strlen(pair);
        }
        if ('\0' == raw_key[0]) {
            pair = strtok_r(NULL, delim, &saveptr);
            continue;
        }
        if (!decode_pair_first) {
            sg_url_decode_inplace(raw_key);
            sg_url_decode_inplace(raw_val);
        }
        if (NULL == strchr(raw_key, '[')) {
            set_string_key(ht, raw_key, raw_val);
        } else if (0 == sg_parse_key_brackets(raw_key, &pk)) {
            sg_set_nested_value(ht, &pk, raw_val);
            sg_free_parsed_key(&pk);
        } else {
            set_string_key(ht, raw_key, raw_val);
            sg_free_parsed_key(&pk);
        }
        pair = strtok_r(NULL, delim, &saveptr);
    }

    free(copy);
}

static void parse_form_encoded(__hashtable__ *ht, const char *body)
{
    parse_delimited_pairs(ht, body, '&', 0);
}

#define SG_JSON_MAX_DEPTH 32
#define SG_JSON_MAX_BODY (8 * 1024 * 1024)

typedef struct {
    const char *pos;
    const char *end;
    int depth;
} sg_json_ctx;

static void sg_json_skip_ws(sg_json_ctx *ctx)
{
    while (ctx->pos < ctx->end) {
        char c = *ctx->pos;

        if (' ' != c && '\t' != c && '\n' != c && '\r' != c) {
            break;
        }
        ctx->pos++;
    }
}

static int sg_json_expect(sg_json_ctx *ctx, char ch)
{
    sg_json_skip_ws(ctx);
    if (ctx->pos >= ctx->end || *ctx->pos != ch) {
        return 0;
    }
    ctx->pos++;

    return 1;
}

static int sg_json_parse_string(sg_json_ctx *ctx, char *out, size_t out_len)
{
    size_t o = 0;

    if (!sg_json_expect(ctx, '"')) {
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
                /* Minimal UTF-8: skip \uXXXX (issue #52 subset). */
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

static int sg_json_parse_number(sg_json_ctx *ctx, char *out, size_t out_len)
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

static int sg_json_parse_literal(sg_json_ctx *ctx, const char *lit, char *out, size_t out_len)
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

static int sg_json_store_scalar(
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
    set_string_key(ht, key, value);

    return 1;
}

static int sg_json_parse_value(
    sg_json_ctx *ctx,
    __hashtable__ *ht,
    const char *key,
    int use_index,
    size_t index
);

static int sg_json_parse_array(sg_json_ctx *ctx, __hashtable__ *ht, const char *key);

static int sg_json_parse_object(sg_json_ctx *ctx, __hashtable__ *ht)
{
    char key_buf[256];

    if (!sg_json_expect(ctx, '{')) {
        return 0;
    }
    sg_json_skip_ws(ctx);
    if (sg_json_expect(ctx, '}')) {
        return 1;
    }
    for (;;) {
        if (!sg_json_parse_string(ctx, key_buf, sizeof(key_buf)) || '\0' == key_buf[0]) {
            return 0;
        }
        if (!sg_json_expect(ctx, ':')) {
            return 0;
        }
        if (!sg_json_parse_value(ctx, ht, key_buf, 0, 0)) {
            return 0;
        }
        sg_json_skip_ws(ctx);
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

static int sg_json_parse_array(sg_json_ctx *ctx, __hashtable__ *ht, const char *key)
{
    size_t idx = 0;
    __hashtable__ *list_ht;

    if (!sg_json_expect(ctx, '[')) {
        return 0;
    }
    sg_json_skip_ws(ctx);
    if (sg_json_expect(ctx, ']')) {
        list_ht = sg_ensure_child(ht, key);
        return 1;
    }
    list_ht = sg_ensure_child(ht, key);
    for (;;) {
        if (!sg_json_parse_value(ctx, list_ht, NULL, 1, idx)) {
            return 0;
        }
        idx++;
        sg_json_skip_ws(ctx);
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

static int sg_json_parse_value(
    sg_json_ctx *ctx,
    __hashtable__ *ht,
    const char *key,
    int use_index,
    size_t index
)
{
    char val_buf[4096];

    if (ctx->depth > SG_JSON_MAX_DEPTH) {
        return 0;
    }
    sg_json_skip_ws(ctx);
    if (ctx->pos >= ctx->end) {
        return 0;
    }
    if ('"' == *ctx->pos) {
        if (!sg_json_parse_string(ctx, val_buf, sizeof(val_buf))) {
            return 0;
        }

        return sg_json_store_scalar(ht, key, use_index, index, val_buf);
    }
    if ('{' == *ctx->pos) {
        __hashtable__ *child;

        if (use_index || NULL == key) {
            return 0;
        }
        ctx->depth++;
        child = sg_ensure_child(ht, key);
        if (!sg_json_parse_object(ctx, child)) {
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
        if (!sg_json_parse_array(ctx, ht, key)) {
            ctx->depth--;

            return 0;
        }
        ctx->depth--;

        return 1;
    }
    if ('-' == *ctx->pos || (*ctx->pos >= '0' && *ctx->pos <= '9')) {
        if (!sg_json_parse_number(ctx, val_buf, sizeof(val_buf))) {
            return 0;
        }

        return sg_json_store_scalar(ht, key, use_index, index, val_buf);
    }
    if ('t' == *ctx->pos) {
        if (!sg_json_parse_literal(ctx, "true", val_buf, sizeof(val_buf))) {
            return 0;
        }

        return sg_json_store_scalar(ht, key, use_index, index, "1");
    }
    if ('f' == *ctx->pos) {
        if (!sg_json_parse_literal(ctx, "false", val_buf, sizeof(val_buf))) {
            return 0;
        }

        return sg_json_store_scalar(ht, key, use_index, index, "");
    }
    if ('n' == *ctx->pos) {
        if (!sg_json_parse_literal(ctx, "null", val_buf, sizeof(val_buf))) {
            return 0;
        }

        return sg_json_store_scalar(ht, key, use_index, index, "");
    }

    return 0;
}

static void parse_json_post(__hashtable__ *ht, const char *body)
{
    sg_json_ctx ctx;

    if (NULL == body || '\0' == body[0]) {
        return;
    }
    if (strlen(body) > SG_JSON_MAX_BODY) {
        return;
    }
    ctx.pos = body;
    ctx.end = body + strlen(body);
    ctx.depth = 0;
    sg_json_skip_ws(&ctx);
    if (ctx.pos >= ctx.end || '{' != *ctx.pos) {
        return;
    }
    (void) sg_json_parse_object(&ctx, ht);
}

#define SG_MULTIPART_MAX_BODY SG_JSON_MAX_BODY

static int sg_extract_boundary(const char *content_type, char *out, size_t out_len)
{
    const char *p;
    const char *start;
    const char *raw;
    size_t len;

    out[0] = '\0';
    raw = getenv("CONTENT_TYPE");
    if (NULL == raw || '\0' == raw[0]) {
        raw = getenv("HTTP_CONTENT_TYPE");
    }
    if (NULL != raw && '\0' != raw[0]) {
        content_type = raw;
    }
    if (NULL == content_type) {
        return 0;
    }
    p = strstr(content_type, "boundary=");
    if (NULL == p) {
        return 0;
    }
    p += 9;
    while (' ' == *p || '\t' == *p) {
        p++;
    }
    if ('"' == *p) {
        p++;
        start = p;
        while ('\0' != *p && '"' != *p) {
            p++;
        }
        len = (size_t) (p - start);
        if ('"' == *p) {
            p++;
        }
    } else {
        start = p;
        while ('\0' != *p && ';' != *p && ' ' != *p && '\t' != *p && '\r' != *p && '\n' != *p) {
            p++;
        }
        len = (size_t) (p - start);
    }
    if (0 == len || len >= out_len) {
        return 0;
    }
    memcpy(out, start, len);
    out[len] = '\0';

    return 1;
}

static const char *sg_find_header_value(const char *headers, const char *name)
{
    size_t name_len = strlen(name);
    const char *line = headers;

    while ('\0' != *line) {
        const char *colon;
        const char *end;
        size_t line_name_len;

        end = strstr(line, "\r\n");
        if (NULL == end) {
            end = line + strlen(line);
        }
        colon = strchr(line, ':');
        if (NULL == colon || colon >= end) {
            if (NULL == end || '\0' == end[0]) {
                break;
            }
            line = end + 2;
            continue;
        }
        line_name_len = (size_t) (colon - line);
        while (line_name_len > 0 && (' ' == line[line_name_len - 1] || '\t' == line[line_name_len - 1])) {
            line_name_len--;
        }
        if (line_name_len == name_len && 0 == strncasecmp(line, name, name_len)) {
            const char *value = colon + 1;

            while (' ' == *value || '\t' == *value) {
                value++;
            }
            return value;
        }
        if (NULL == end || '\0' == end[0]) {
            break;
        }
        line = end + 2;
    }

    return NULL;
}

static int sg_multipart_param(const char *disposition, const char *param, char *out, size_t out_len)
{
    char needle[64];
    const char *p;
    const char *start;
    size_t len;

    snprintf(needle, sizeof(needle), "%s=\"", param);
    p = strstr(disposition, needle);
    if (NULL == p) {
        return 0;
    }
    p += strlen(needle);
    start = p;
    while ('\0' != *p && '"' != *p) {
        p++;
    }
    len = (size_t) (p - start);
    if (len + 1 > out_len) {
        return 0;
    }
    memcpy(out, start, len);
    out[len] = '\0';

    return 1;
}

static void sg_set_file_entry(
    __hashtable__ *files,
    const char *field,
    const char *filename,
    const char *part_type,
    const char *content,
    size_t content_len
)
{
    __hashtable__ *entry;
    char tmp_path[] = "/tmp/phpc_upload_XXXXXX";
    int fd;
    FILE *fp;
    char size_buf[32];

    entry = sg_ensure_child(files, field);
    set_string_key(entry, "name", filename);
    set_string_key(entry, "type", (NULL != part_type && '\0' != part_type[0])
        ? part_type : "application/octet-stream");
    fd = mkstemp(tmp_path);
    if (fd < 0) {
        set_string_key(entry, "error", "1");

        return;
    }
    fp = fdopen(fd, "wb");
    if (NULL == fp) {
        set_string_key(entry, "error", "1");

        return;
    }
    if (content_len > 0 && 1 != fwrite(content, 1, content_len, fp)) {
        fclose(fp);
        unlink(tmp_path);
        set_string_key(entry, "error", "1");

        return;
    }
    fclose(fp);
    set_string_key(entry, "tmp_name", tmp_path);
    set_string_key(entry, "error", "0");
    snprintf(size_buf, sizeof(size_buf), "%zu", content_len);
    set_string_key(entry, "size", size_buf);
}

static char *sg_normalize_body_newlines(const char *body, size_t *out_len)
{
    size_t len;
    size_t i;
    size_t w;
    char *copy;

    if (NULL == body) {
        *out_len = 0;

        return NULL;
    }
    len = strlen(body);
    copy = (char *) malloc(len + 1);
    if (NULL == copy) {
        *out_len = 0;

        return NULL;
    }
    for (i = 0, w = 0; i < len; i++) {
        if ('\r' == body[i]) {
            if (i + 1 < len && '\n' == body[i + 1]) {
                i++;
            }
            copy[w++] = '\n';
        } else {
            copy[w++] = body[i];
        }
    }
    copy[w] = '\0';
    *out_len = w;

    return copy;
}

static void parse_multipart_post(
    __hashtable__ *post,
    __hashtable__ *files,
    const char *content_type,
    const char *body
)
{
    char boundary[256];
    char delim[260];
    char *normalized;
    const char *cursor;
    const char *end;
    size_t delim_len;
    size_t body_len;

    if (NULL == body || '\0' == body[0]) {
        return;
    }
    normalized = sg_normalize_body_newlines(body, &body_len);
    if (NULL == normalized) {
        return;
    }
    if (body_len > SG_MULTIPART_MAX_BODY) {
        free(normalized);

        return;
    }
    body = normalized;
    if (!sg_extract_boundary(content_type, boundary, sizeof(boundary))) {
        return;
    }
    snprintf(delim, sizeof(delim), "--%s", boundary);
    delim_len = strlen(delim);
    cursor = body;
    end = body + body_len;
    while (cursor < end) {
        const char *part_start;
        const char *headers_end;
        const char *part_end;
        const char *disposition;
        char field[256];
        char filename[256];
        size_t content_len;

        part_start = strstr(cursor, delim);
        if (NULL == part_start) {
            break;
        }
        part_start += delim_len;
        if (part_start < end && '\r' == part_start[0] && '\n' == part_start[1]) {
            part_start += 2;
        } else if (part_start < end && '\n' == part_start[0]) {
            part_start += 1;
        }
        if (part_start + 2 <= end && '-' == part_start[0] && '-' == part_start[1]) {
            break;
        }
        part_end = strstr(part_start, delim);
        if (NULL == part_end) {
            part_end = end;
        }
        headers_end = strstr(part_start, "\n\n");
        if (NULL == headers_end || headers_end >= part_end) {
            cursor = part_end;
            continue;
        }
        headers_end += 2;
        disposition = sg_find_header_value(part_start, "Content-Disposition");
        if (NULL == disposition) {
            cursor = part_end;
            continue;
        }
        if (!sg_multipart_param(disposition, "name", field, sizeof(field)) || '\0' == field[0]) {
            cursor = part_end;
            continue;
        }
        content_len = (size_t) (part_end - headers_end);
        while (content_len > 0
            && ('\r' == headers_end[content_len - 1]
                || '\n' == headers_end[content_len - 1])) {
            content_len--;
        }
        if (sg_multipart_param(disposition, "filename", filename, sizeof(filename))) {
            const char *part_type = sg_find_header_value(part_start, "Content-Type");

            sg_set_file_entry(
                files,
                field,
                filename,
                part_type,
                headers_end,
                content_len
            );
        } else {
            char *copy;
            char pair_buf[4096];

            if (content_len + strlen(field) + 2 >= sizeof(pair_buf)) {
                cursor = part_end;
                continue;
            }
            copy = (char *) malloc(content_len + 1);
            if (NULL == copy) {
                cursor = part_end;
                continue;
            }
            memcpy(copy, headers_end, content_len);
            copy[content_len] = '\0';
            snprintf(pair_buf, sizeof(pair_buf), "%s=%s", field, copy);
            free(copy);
            parse_delimited_pairs(post, pair_buf, '&', 0);
        }
        cursor = part_end;
    }

    free(normalized);
}

static void populate_post_body(__hashtable__ *ht, const char *content_type, const char *body)
{
    if (0 == strcmp(content_type, "application/json")) {
        parse_json_post(ht, body);
    } else if (0 == strncmp(content_type, "multipart/form-data", 19)) {
        parse_multipart_post(ht, sg_FILES, content_type, body);
    } else {
        parse_form_encoded(ht, body);
    }
}

static void parse_cookie_header(__hashtable__ *ht, const char *header)
{
    parse_delimited_pairs(ht, header, ';', 1);
}

static const char *env_or_empty(const char *name)
{
    const char *v = getenv(name);

    return NULL != v ? v : "";
}

static char *read_request_body_from_env(size_t *out_len)
{
    const char *path = getenv("REQUEST_BODY_FILE");
    FILE *fp;
    char *buf;
    size_t cap;
    size_t len;

    *out_len = 0;
    if (NULL != path && '\0' != path[0]) {
        fp = fopen(path, "rb");
        if (NULL == fp) {
            return NULL;
        }
        cap = 4096;
        len = 0;
        buf = (char *) malloc(cap);
        if (NULL == buf) {
            fclose(fp);

            return NULL;
        }
        for (;;) {
            size_t n;

            if (len + 4096 > cap) {
                char *grown;

                cap *= 2;
                if (cap > SG_MULTIPART_MAX_BODY + 1) {
                    free(buf);
                    fclose(fp);

                    return NULL;
                }
                grown = (char *) realloc(buf, cap);
                if (NULL == grown) {
                    free(buf);
                    fclose(fp);

                    return NULL;
                }
                buf = grown;
            }
            n = fread(buf + len, 1, 4096, fp);
            if (0 == n) {
                break;
            }
            len += n;
            if (len > SG_MULTIPART_MAX_BODY) {
                free(buf);
                fclose(fp);

                return NULL;
            }
        }
        fclose(fp);
        buf[len] = '\0';
        *out_len = len;

        return buf;
    }

    {
        const char *inline_body = env_or_empty("REQUEST_BODY");

        len = strlen(inline_body);
        if (0 == len) {
            return NULL;
        }
        buf = (char *) malloc(len + 1);
        if (NULL == buf) {
            return NULL;
        }
        memcpy(buf, inline_body, len + 1);
        *out_len = len;

        return buf;
    }
}

static const char *request_method_for(const char *post_body)
{
    const char *method = getenv("REQUEST_METHOD");

    if (NULL != method && '\0' != method[0]) {
        return method;
    }

    return ('\0' != post_body[0]) ? "POST" : "GET";
}

static void normalize_content_type(const char *raw, char *out, size_t out_len)
{
    size_t i;
    size_t end;

    if (NULL == raw) {
        out[0] = '\0';

        return;
    }
    strncpy(out, raw, out_len - 1);
    out[out_len - 1] = '\0';
    for (i = 0; '\0' != out[i]; i++) {
        if (out[i] >= 'A' && out[i] <= 'Z') {
            out[i] = (char) (out[i] - 'A' + 'a');
        }
    }
    end = strlen(out);
    for (i = 0; i < end; i++) {
        if (';' == out[i]) {
            while (end > i + 1 && (' ' == out[end - 1] || '\t' == out[end - 1])) {
                end--;
            }
            out[i] = '\0';
            break;
        }
    }
}

static const char *resolve_content_type(char *buf, size_t buf_len)
{
    const char *ct = getenv("CONTENT_TYPE");

    if (NULL == ct || '\0' == ct[0]) {
        ct = getenv("HTTP_CONTENT_TYPE");
    }
    if (NULL == ct) {
        buf[0] = '\0';

        return buf;
    }
    normalize_content_type(ct, buf, buf_len);

    return buf;
}

static int method_is(const char *method, const char *name)
{
    size_t i;

    if (NULL == method) {
        return 0;
    }
    for (i = 0; '\0' != method[i] && '\0' != name[i]; i++) {
        char a = method[i];
        char b = name[i];

        if (a >= 'a' && a <= 'z') {
            a = (char) (a - 'a' + 'A');
        }
        if (b >= 'a' && b <= 'z') {
            b = (char) (b - 'a' + 'A');
        }
        if (a != b) {
            return 0;
        }
    }

    return '\0' == method[i] && '\0' == name[i];
}

static int should_populate_post(
    const char *method,
    const char *content_type,
    const char *post_body
)
{
    if ('\0' == post_body[0]) {
        return 0;
    }
    if (method_is(method, "PUT") || method_is(method, "PATCH") || method_is(method, "DELETE")) {
        return 0 == strcmp(content_type, "application/x-www-form-urlencoded");
    }
    if (method_is(method, "POST")) {
        if ('\0' == content_type[0]) {
            return 1;
        }
        if (0 == strcmp(content_type, "application/x-www-form-urlencoded")) {
            return 1;
        }
        if (0 == strncmp(content_type, "multipart/form-data", 19)) {
            return 1;
        }
        if (0 == strcmp(content_type, "application/json")) {
            return 1;
        }

        return 0;
    }

    return 0;
}

static int is_cgi_header_env_key(const char *key)
{
    if (0 == strncmp(key, "HTTP_", 5)) {
        return 1;
    }

    return 0 == strcmp(key, "CONTENT_TYPE") || 0 == strcmp(key, "CONTENT_LENGTH");
}

static void apply_cgi_headers_from_environ(__hashtable__ *server)
{
    char **env;
    char key_buf[256];

    for (env = phpc_environ; NULL != env && NULL != *env; env++) {
        const char *eq = strchr(*env, '=');
        const char *value;

        if (NULL == eq) {
            continue;
        }
        if ((size_t) (eq - *env) >= sizeof(key_buf)) {
            continue;
        }
        memcpy(key_buf, *env, (size_t) (eq - *env));
        key_buf[eq - *env] = '\0';
        if (!is_cgi_header_env_key(key_buf)) {
            continue;
        }
        value = eq + 1;
        set_string_key(server, key_buf, value);
    }
}

static int sg_is_https_request(void)
{
    const char *https = getenv("HTTPS");

    if (NULL != https && '\0' != https[0] && 0 != strcmp(https, "0")
        && 0 != strcasecmp(https, "off")) {
        return 1;
    }
    {
        const char *proto = getenv("HTTP_X_FORWARDED_PROTO");

        if (NULL != proto && 0 == strcasecmp(proto, "https")) {
            return 1;
        }
    }

    return 0;
}

static int sg_parse_host_port(const char *host, char *name_out, size_t name_len, int *port_out)
{
    const char *colon;

    name_out[0] = '\0';
    *port_out = 0;
    if ('\0' == host[0]) {
        return 0;
    }
    if ('[' == host[0]) {
        const char *close = strchr(host, ']');

        if (NULL != close) {
            size_t name_part = (size_t) (close - host - 1);

            if (name_part >= name_len) {
                name_part = name_len - 1;
            }
            memcpy(name_out, host + 1, name_part);
            name_out[name_part] = '\0';
            if (']' == close[0] && ':' == close[1]) {
                *port_out = atoi(close + 2);
            }

            return 1;
        }
    }
    colon = strrchr(host, ':');
    if (NULL != colon && NULL == strchr(colon + 1, ':')) {
        int port = atoi(colon + 1);

        if (port > 0) {
            size_t name_part = (size_t) (colon - host);

            if (name_part >= name_len) {
                name_part = name_len - 1;
            }
            memcpy(name_out, host, name_part);
            name_out[name_part] = '\0';
            *port_out = port;

            return 1;
        }
    }
    strncpy(name_out, host, name_len - 1);
    name_out[name_len - 1] = '\0';

    return 1;
}

static int sg_resolve_server_port(int https, int port_from_host)
{
    const char *from_env = getenv("SERVER_PORT");

    if (NULL != from_env && '\0' != from_env[0]) {
        int port = atoi(from_env);

        if (port > 0) {
            return port;
        }
    }
    if (port_from_host > 0) {
        return port_from_host;
    }

    return https ? 443 : 80;
}

static void apply_scheme_and_port(__hashtable__ *server)
{
    const char *host = env_or_empty("HTTP_HOST");
    int https = sg_is_https_request();
    const char *scheme = https ? "https" : "http";
    char server_name[256];
    int port_from_host = 0;
    int port;
    char port_buf[16];

    if ('\0' != host[0]) {
        set_string_key(server, "HTTP_HOST", host);
        sg_parse_host_port(host, server_name, sizeof(server_name), &port_from_host);
        if ('\0' != server_name[0]) {
            set_string_key(server, "SERVER_NAME", server_name);
        }
    }

    set_string_key(server, "REQUEST_SCHEME", scheme);
    if (https) {
        set_string_key(server, "HTTPS", "on");
    }

    port = sg_resolve_server_port(https, port_from_host);
    snprintf(port_buf, sizeof(port_buf), "%d", port);
    set_string_key(server, "SERVER_PORT", port_buf);
}

static void resolve_script_filename(
    const char *script_name,
    char *out,
    size_t out_len
) {
    const char *from_env = getenv("SCRIPT_FILENAME");

    out[0] = '\0';
    if (NULL != from_env && '\0' != from_env[0]) {
        strncpy(out, from_env, out_len - 1);
        out[out_len - 1] = '\0';

        return;
    }

    {
        const char *document_root = getenv("DOCUMENT_ROOT");
        size_t root_len;

        if (NULL == document_root || '\0' == document_root[0]
            || NULL == script_name || '\0' == script_name[0]) {
            return;
        }
        root_len = strlen(document_root);
        while (root_len > 0 && '/' == document_root[root_len - 1]) {
            root_len--;
        }
        snprintf(out, out_len, "%.*s%s", (int) root_len, document_root, script_name);
    }
}

static void derive_path_info(const char *script_name, const char *request_uri, char *out, size_t out_len)
{
    char path_buf[1024];
    const char *path;
    const char *q;
    size_t script_len;
    size_t path_len;

    out[0] = '\0';
    if ('\0' == script_name[0] || '\0' == request_uri[0]) {
        return;
    }

    path = request_uri;
    q = strchr(request_uri, '?');
    if (NULL != q) {
        path_len = (size_t) (q - request_uri);
        if (path_len >= sizeof(path_buf)) {
            path_len = sizeof(path_buf) - 1;
        }
        memcpy(path_buf, request_uri, path_len);
        path_buf[path_len] = '\0';
        path = path_buf;
    }

    script_len = strlen(script_name);
    if (0 != strncmp(path, script_name, script_len)) {
        return;
    }

    strncpy(out, path + script_len, out_len - 1);
    out[out_len - 1] = '\0';
}

void __superglobals__refresh(void)
{
    const char *query_string = env_or_empty("QUERY_STRING");
    size_t post_body_len = 0;
    char *post_body_owned = read_request_body_from_env(&post_body_len);
    const char *post_body = NULL != post_body_owned ? post_body_owned : "";
    const char *method = request_method_for(post_body);
    char content_type_buf[256];
    const char *content_type = resolve_content_type(content_type_buf, sizeof(content_type_buf));
    int populate_post = should_populate_post(method, content_type, post_body);
    const char *script_name = env_or_empty("SCRIPT_NAME");
    const char *request_uri = getenv("REQUEST_URI");
    char path_info[512];
    char script_filename[1024];
    char request_uri_buf[1024];

    if (NULL == request_uri || '\0' == request_uri[0]) {
        snprintf(request_uri_buf, sizeof(request_uri_buf), "%s", script_name);
        if ('\0' != query_string[0]) {
            size_t used = strlen(request_uri_buf);
            snprintf(
                request_uri_buf + used,
                sizeof(request_uri_buf) - used,
                "?%s",
                query_string
            );
        }
        request_uri = request_uri_buf;
    }

    if ('\0' == script_name[0]) {
        script_name = "/index.php";
    }

    sg_GET = __hashtable__alloc();
    parse_form_encoded(sg_GET, query_string);

    sg_FILES = __hashtable__alloc();
    sg_POST = __hashtable__alloc();
    if (populate_post) {
        populate_post_body(sg_POST, content_type, post_body);
    }

    sg_REQUEST = __hashtable__alloc();
    if ('\0' != query_string[0]) {
        parse_form_encoded(sg_REQUEST, query_string);
    }
    if (populate_post) {
        populate_post_body(sg_REQUEST, content_type, post_body);
    }

    sg_SERVER = __hashtable__alloc();
    set_string_key(sg_SERVER, "REQUEST_METHOD", method);
    set_string_key(sg_SERVER, "QUERY_STRING", query_string);
    set_string_key(sg_SERVER, "SCRIPT_NAME", script_name);
    set_string_key(sg_SERVER, "PHP_SELF", script_name);
    resolve_script_filename(script_name, script_filename, sizeof(script_filename));
    if ('\0' != script_filename[0]) {
        set_string_key(sg_SERVER, "SCRIPT_FILENAME", script_filename);
    }
    set_string_key(sg_SERVER, "REQUEST_URI", request_uri);
    set_string_key(sg_SERVER, "GATEWAY_INTERFACE", "CGI/1.1");
    {
        const char *server_protocol = getenv("SERVER_PROTOCOL");

        if (NULL == server_protocol || '\0' == server_protocol[0]) {
            server_protocol = "HTTP/1.1";
        }
        set_string_key(sg_SERVER, "SERVER_PROTOCOL", server_protocol);
    }
    set_string_key(sg_SERVER, "SERVER_SOFTWARE", "PHP-Compiler-AOT");

    {
        const char *document_root = getenv("DOCUMENT_ROOT");

        if (NULL != document_root && '\0' != document_root[0]) {
            set_string_key(sg_SERVER, "DOCUMENT_ROOT", document_root);
        }
    }

    {
        const char *remote_addr = getenv("REMOTE_ADDR");

        if (NULL != remote_addr && '\0' != remote_addr[0]) {
            set_string_key(sg_SERVER, "REMOTE_ADDR", remote_addr);
        }
    }
    {
        const char *remote_port = getenv("REMOTE_PORT");

        if (NULL != remote_port && '\0' != remote_port[0]) {
            set_string_key(sg_SERVER, "REMOTE_PORT", remote_port);
        }
    }

    {
        const char *path_info_env = getenv("PATH_INFO");

        if (NULL != path_info_env && '\0' != path_info_env[0]) {
            set_string_key(sg_SERVER, "PATH_INFO", path_info_env);
        } else {
            derive_path_info(script_name, request_uri, path_info, sizeof(path_info));
            if ('\0' != path_info[0]) {
                set_string_key(sg_SERVER, "PATH_INFO", path_info);
            }
        }
    }

    apply_cgi_headers_from_environ(sg_SERVER);
    apply_scheme_and_port(sg_SERVER);

    sg_COOKIE = __hashtable__alloc();
    parse_cookie_header(sg_COOKIE, env_or_empty("HTTP_COOKIE"));
    if (NULL == sg_ENV) {
        sg_ENV = __hashtable__alloc();
    }
    if (NULL == sg_FILES) {
        sg_FILES = __hashtable__alloc();
    }
    if (NULL == sg_SESSION) {
        sg_SESSION = __hashtable__alloc();
    }

    if (NULL != post_body_owned) {
        free(post_body_owned);
    }
}

static long long nf_pow10(int decimals)
{
    long long scale = 1;
    int i;

    if (decimals < 0) {
        return 1;
    }
    if (decimals > 20) {
        decimals = 20;
    }
    for (i = 0; i < decimals; i++) {
        scale *= 10;
    }

    return scale;
}

static long long nf_round_scaled(double num, long long scale)
{
    double product = num * (double) scale;
    if (product >= 0.0) {
        return (long long) (product + 0.5);
    }

    return (long long) (product - 0.5);
}

static size_t nf_strlen(__string__ *s)
{
    if (NULL == s) {
        return 0;
    }

    return (size_t) *((long long *) ((char *) s + sizeof(void *)));
}

static const char *nf_strdata(__string__ *s)
{
    if (NULL == s) {
        return "";
    }

    return (const char *) s + sizeof(void *) + sizeof(long long);
}

static void nf_append_char(char *buf, size_t *pos, size_t cap, char ch)
{
    if (*pos + 1 < cap) {
        buf[(*pos)++] = ch;
    }
}

static void nf_append_str(char *buf, size_t *pos, size_t cap, const char *src, size_t len)
{
    size_t i;

    for (i = 0; i < len && *pos + 1 < cap; i++) {
        buf[(*pos)++] = src[i];
    }
}

static void nf_format_unsigned(long long value, char *buf, size_t cap, __string__ *thou_sep)
{
    char digits[32];
    size_t digit_len = 0;
    size_t pos = 0;
    size_t sep_len;
    const char *sep;
    size_t i;

    if (value < 0) {
        value = -value;
    }
    if (0 == value) {
        nf_append_char(buf, &pos, cap, '0');
        buf[pos] = '\0';

        return;
    }
    while (value > 0 && digit_len < sizeof(digits)) {
        digits[digit_len++] = (char) ('0' + (value % 10));
        value /= 10;
    }
    sep = nf_strdata(thou_sep);
    sep_len = nf_strlen(thou_sep);
    for (i = digit_len; i > 0; i--) {
        size_t from_left = digit_len - i;

        if (sep_len > 0 && from_left > 0 && (digit_len - from_left) % 3 == 0) {
            nf_append_str(buf, &pos, cap, sep, sep_len);
        }
        nf_append_char(buf, &pos, cap, digits[i - 1]);
    }
    buf[pos] = '\0';
}

static void nf_format_fraction(long long frac, long long decimals, char *buf, size_t cap)
{
    size_t pos = 0;
    int pad;
    long long scale = nf_pow10((int) decimals);
    int i;

    for (i = 0; i < (int) decimals; i++) {
        scale /= 10;
        if (0 == scale) {
            break;
        }
        nf_append_char(buf, &pos, cap, (char) ('0' + ((frac / scale) % 10)));
    }
  pad = (int) decimals - (int) pos;
    while (pad-- > 0 && pos + 1 < cap) {
        nf_append_char(buf, &pos, cap, '0');
    }
    buf[pos] = '\0';
}

typedef struct __value__ {
    char type;
    char value[8];
} __value__;

#define PHPC_TYPE_NULL 0
#define PHPC_TYPE_LONG 1
#define PHPC_TYPE_BOOL 2
#define PHPC_TYPE_DOUBLE 3
#define PHPC_TYPE_STRING 4

extern long long __value__readLong(__value__ *);
extern double __value__readDouble(__value__ *);
extern __string__ *__value__readString(__value__ *);

#define SPRINTF_MAX_OUT 4096

static int sp_type_kind(char type_byte)
{
    return (int) (type_byte & 127);
}

static void sp_append_decimal_ll(char *buf, size_t *pos, size_t cap, long long value)
{
    char digits[32];
    size_t digit_len = 0;
    int negative = 0;

    if (value < 0) {
        negative = 1;
        value = -value;
    }
    if (0 == value) {
        nf_append_char(buf, pos, cap, '0');

        return;
    }
    while (value > 0 && digit_len < sizeof(digits)) {
        digits[digit_len++] = (char) ('0' + (value % 10));
        value /= 10;
    }
    if (negative) {
        nf_append_char(buf, pos, cap, '-');
    }
    while (digit_len > 0) {
        nf_append_char(buf, pos, cap, digits[--digit_len]);
    }
}

static void sp_append_float(char *buf, size_t *pos, size_t cap, double num)
{
    char frac_buf[32];
    long long scale;
    long long scaled;
    long long int_part;
    long long frac_part;

    scale = nf_pow10(6);
    scaled = nf_round_scaled(num, scale);
    if (scaled < 0) {
        nf_append_char(buf, pos, cap, '-');
        scaled = -scaled;
    }
    int_part = scaled / scale;
    frac_part = scaled % scale;
    {
        char int_buf[64];

        nf_format_unsigned(int_part, int_buf, sizeof(int_buf), NULL);
        nf_append_str(buf, pos, cap, int_buf, strlen(int_buf));
    }
    nf_append_char(buf, pos, cap, '.');
    nf_format_fraction(frac_part, 6, frac_buf, sizeof(frac_buf));
    nf_append_str(buf, pos, cap, frac_buf, strlen(frac_buf));
}

static void sp_append_spec(
    char *buf,
    size_t *pos,
    size_t cap,
    __value__ *v,
    char spec
) {
    int kind = sp_type_kind(v->type);

    switch (spec) {
        case 's':
            if (PHPC_TYPE_STRING == kind) {
                __string__ *s = __value__readString(v);
                const char *data = nf_strdata(s);
                size_t len = nf_strlen(s);

                nf_append_str(buf, pos, cap, data, len);
            } else if (PHPC_TYPE_LONG == kind || PHPC_TYPE_BOOL == kind) {
                sp_append_decimal_ll(buf, pos, cap, __value__readLong(v));
            } else if (PHPC_TYPE_DOUBLE == kind) {
                sp_append_float(buf, pos, cap, __value__readDouble(v));
            } else if (PHPC_TYPE_NULL == kind) {
                return;
            }
            return;
        case 'd':
            if (PHPC_TYPE_LONG == kind || PHPC_TYPE_BOOL == kind) {
                sp_append_decimal_ll(buf, pos, cap, __value__readLong(v));
            } else if (PHPC_TYPE_DOUBLE == kind) {
                sp_append_decimal_ll(buf, pos, cap, (long long) __value__readDouble(v));
            } else if (PHPC_TYPE_NULL == kind) {
                nf_append_char(buf, pos, cap, '0');
            } else if (PHPC_TYPE_STRING == kind) {
                sp_append_decimal_ll(buf, pos, cap, strtoll(nf_strdata(__value__readString(v)), NULL, 10));
            }
            return;
        case 'f':
            if (PHPC_TYPE_DOUBLE == kind) {
                sp_append_float(buf, pos, cap, __value__readDouble(v));
            } else if (PHPC_TYPE_LONG == kind || PHPC_TYPE_BOOL == kind) {
                sp_append_float(buf, pos, cap, (double) __value__readLong(v));
            } else if (PHPC_TYPE_NULL == kind) {
                nf_append_char(buf, pos, cap, '0');
                nf_append_char(buf, pos, cap, '.');
                nf_append_char(buf, pos, cap, '0');
            } else if (PHPC_TYPE_STRING == kind) {
                sp_append_float(buf, pos, cap, strtod(nf_strdata(__value__readString(v)), NULL));
            }
            return;
        default:
            return;
    }
}

/**
 * LLVM/AOT runtime: sprintf() subset (%s, %d, %f, %%).
 */
__string__ *__compiler_sprintf(__string__ *fmt, long long argc, __value__ *argv)
{
    const char *format;
    size_t fmt_len;
    size_t pos = 0;
    size_t arg_idx = 0;
    size_t i;
    char out[SPRINTF_MAX_OUT + 1];

    if (NULL == fmt) {
        return cstr_to_string("");
    }
    format = nf_strdata(fmt);
    fmt_len = nf_strlen(fmt);
    for (i = 0; i < fmt_len; i++) {
        char ch = format[i];

        if ('%' != ch) {
            nf_append_char(out, &pos, sizeof(out), ch);
            continue;
        }
        if (i + 1 >= fmt_len) {
            break;
        }
        ch = format[++i];
        if ('%' == ch) {
            nf_append_char(out, &pos, sizeof(out), '%');
            continue;
        }
        if (arg_idx >= (size_t) argc) {
            break;
        }
        if (NULL != argv) {
            sp_append_spec(out, &pos, sizeof(out), argv + arg_idx, ch);
        }
        arg_idx++;
    }
    out[pos] = '\0';

    return cstr_to_string(out);
}

/**
 * LLVM/AOT runtime: number_format() subset (int/float, custom separators).
 */
__string__ *__compiler_number_format(
    double num,
    long long decimals,
    __string__ *dec_sep,
    __string__ *thou_sep
) {
    char buf[128];
    char int_buf[64];
    char frac_buf[32];
    long long scale;
    long long scaled;
    long long int_part;
    long long frac_part;
    size_t pos = 0;
    size_t dec_len;
    size_t frac_len;
    const char *dec;

    if (decimals < 0) {
        decimals = 0;
    }
    if (decimals > 20) {
        decimals = 20;
    }
    scale = nf_pow10((int) decimals);
    scaled = nf_round_scaled(num, scale);
    if (scaled < 0) {
        nf_append_char(buf, &pos, sizeof(buf), '-');
        scaled = -scaled;
    }
    int_part = scaled / scale;
    frac_part = scaled % scale;
    nf_format_unsigned(int_part, int_buf, sizeof(int_buf), thou_sep);
    nf_append_str(buf, &pos, sizeof(buf), int_buf, strlen(int_buf));
    if (decimals > 0) {
        dec = nf_strdata(dec_sep);
        dec_len = nf_strlen(dec_sep);
        if (0 == dec_len) {
            dec = ".";
            dec_len = 1;
        }
        nf_append_str(buf, &pos, sizeof(buf), dec, dec_len);
        nf_format_fraction(frac_part, decimals, frac_buf, sizeof(frac_buf));
        frac_len = strlen(frac_buf);
        nf_append_str(buf, &pos, sizeof(buf), frac_buf, frac_len);
    }
    buf[pos] = '\0';

    return cstr_to_string(buf);
}

static int st_is_space(char ch)
{
    return ch == ' ' || ch == '\t' || ch == '\n' || ch == '\r' || ch == '\v' || ch == '\f';
}

static int st_is_tag_char(char ch)
{
    return (ch >= 'a' && ch <= 'z') || (ch >= 'A' && ch <= 'Z') || (ch >= '0' && ch <= '9');
}

static int st_find_substr(const char *hay, size_t hlen, const char *needle, size_t nlen, size_t from)
{
    size_t i;

    if (nlen == 0 || from + nlen > hlen) {
        return -1;
    }
    for (i = from; i + nlen <= hlen; i++) {
        if (memcmp(hay + i, needle, nlen) == 0) {
            return (int) i;
        }
    }

    return -1;
}

static void st_tolower_buf(char *buf, size_t len)
{
    size_t i;

    for (i = 0; i < len; i++) {
        if (buf[i] >= 'A' && buf[i] <= 'Z') {
            buf[i] = (char) (buf[i] - 'A' + 'a');
        }
    }
}

static int st_extract_tag_name(const char *content, size_t clen, char *out, size_t out_cap)
{
    size_t i = 0;
    size_t start;

    while (i < clen && st_is_space(content[i])) {
        i++;
    }
    if (i < clen && content[i] == '/') {
        i++;
    }
    if (i >= clen) {
        return 0;
    }
    start = i;
    while (i < clen) {
        char ch = content[i];
        if (st_is_space(ch) || ch == '>' || ch == '/') {
            break;
        }
        if (!st_is_tag_char(ch)) {
            return 0;
        }
        i++;
    }
    if (start == i || i - start >= out_cap) {
        return 0;
    }
    memcpy(out, content + start, i - start);
    out[i - start] = '\0';
    st_tolower_buf(out, i - start);

    return 1;
}

static int st_tag_allowed(const char *name, const char *allowed_tags[], int allowed_count)
{
    int i;

    for (i = 0; i < allowed_count; i++) {
        if (strcmp(name, allowed_tags[i]) == 0) {
            return 1;
        }
    }

    return 0;
}

static int st_parse_allowed(const char *allowed, size_t alen, char tags[][32], int max_tags)
{
    int count = 0;
    size_t i = 0;

    while (i < alen && count < max_tags) {
        int gt;
        char content[128];
        size_t clen;

        if (allowed[i] != '<') {
            i++;
            continue;
        }
        gt = st_find_substr(allowed, alen, ">", 1, i + 1);
        if (gt < 0) {
            break;
        }
        clen = (size_t) gt - i - 1;
        if (clen >= sizeof(content)) {
            clen = sizeof(content) - 1;
        }
        memcpy(content, allowed + i + 1, clen);
        content[clen] = '\0';
        if (st_extract_tag_name(content, clen, tags[count], sizeof(tags[0]))) {
            count++;
        }
        i = (size_t) gt + 1;
    }

    return count;
}

/**
 * LLVM/AOT runtime: strip_tags() subset (mirrors VmString::stripTags).
 */
__string__ *__compiler_strip_tags(__string__ *input, __string__ *allowed)
{
    const char *src;
    size_t slen;
    const char *allow_src = "";
    size_t alen = 0;
    char allowed_list[32][32];
    int allowed_count = 0;
    char *out;
    size_t out_cap;
    size_t out_len = 0;
    size_t i = 0;

    src = nf_strdata(input);
    slen = nf_strlen(input);
    if (allowed != NULL) {
        allow_src = nf_strdata(allowed);
        alen = nf_strlen(allowed);
        if (alen > 0) {
            allowed_count = st_parse_allowed(allow_src, alen, allowed_list, 32);
        }
    }
    out_cap = slen + 1;
    out = (char *) malloc(out_cap);
    if (out == NULL) {
        return cstr_to_string("");
    }

    while (i < slen) {
        if (src[i] != '<') {
            out[out_len++] = src[i++];
            continue;
        }
        if (i + 3 < slen && memcmp(src + i, "<!--", 4) == 0) {
            int end = st_find_substr(src, slen, "-->", 3, i + 4);
            if (end >= 0) {
                i = (size_t) end + 3;
                continue;
            }
        }
        if (i + 1 < slen && memcmp(src + i, "<?", 2) == 0) {
            int end = st_find_substr(src, slen, "?>", 2, i + 2);
            if (end >= 0) {
                i = (size_t) end + 2;
                continue;
            }
        }
        {
            int gt = st_find_substr(src, slen, ">", 1, i + 1);
            char tag_name[32];
            char content[256];
            size_t clen;

            if (gt < 0) {
                out[out_len++] = src[i++];
                continue;
            }
            clen = (size_t) gt - i - 1;
            if (clen >= sizeof(content)) {
                clen = sizeof(content) - 1;
            }
            memcpy(content, src + i + 1, clen);
            content[clen] = '\0';
            if (st_extract_tag_name(content, clen, tag_name, sizeof(tag_name))
                && allowed_count > 0 && st_tag_allowed(tag_name, allowed_list, allowed_count)) {
                size_t tag_len = (size_t) gt - i + 1;
                if (out_len + tag_len >= out_cap) {
                    out_cap = out_cap * 2 + tag_len;
                    {
                        char *grown = (char *) realloc(out, out_cap);
                        if (grown == NULL) {
                            free(out);
                            return cstr_to_string("");
                        }
                        out = grown;
                    }
                }
                memcpy(out + out_len, src + i, tag_len);
                out_len += tag_len;
            }
            i = (size_t) gt + 1;
        }
    }
    out[out_len] = '\0';
    {
        __string__ *result = cstr_to_string(out);
        free(out);

        return result;
    }
}

/*
 * UTF-8 character count for mb_strlen() JIT/AOT (issue #158).
 */
long long __compiler_utf8_strlen(__string__ *input)
{
    const char *src;
    size_t slen;
    size_t i = 0;
    long long count = 0;

    if (input == NULL) {
        return 0;
    }
    src = nf_strdata(input);
    slen = nf_strlen(input);
    while (i < slen) {
        unsigned char b = (unsigned char) src[i];
        if (b < 0x80) {
            i += 1;
        } else if ((b & 0xE0) == 0xC0 && i + 1 < slen) {
            i += 2;
        } else if ((b & 0xF0) == 0xE0 && i + 2 < slen) {
            i += 3;
        } else if ((b & 0xF8) == 0xF0 && i + 3 < slen) {
            i += 4;
        } else {
            i += 1;
        }
        count++;
    }

    return count;
}

/*
 * Zend parity for missing array string keys (issue #273).
 * Called from JIT __hashtable__readStringKeyValue when lookup returns NULL.
 */
void __compiler_undefined_array_key_warning_cstr(const char *key, size_t len)
{
    if (!key) {
        return;
    }
    fprintf(stderr, "Warning: Undefined array key \"%.*s\"\n", (int) len, key);
}

void __compiler_undefined_array_key_warning_long(long long key)
{
    fprintf(stderr, "Warning: Undefined array key %lld\n", key);
}

/*
 * Pending response headers for header_list() / header_remove() JIT/AOT (#311).
 * Mirrors PHPCompiler\Web\ResponseContext (insertion order, case-insensitive names).
 */
typedef struct __phpc_header_node {
    __string__ *line;
    struct __phpc_header_node *next;
} __phpc_header_node;

static __phpc_header_node *phpc_pending_head = NULL;
static __phpc_header_node *phpc_pending_tail = NULL;
static int phpc_response_headers_flushed = 0;

/** LLVM module globals (HttpResponseCode); defined in generated .o */
extern int __phpc_http_response_status;
extern int __phpc_http_response_status_explicit;

static int phpc_header_name_from_line(__string__ *line, char *buf, size_t bufsz)
{
    const char *s;
    size_t len;
    size_t i = 0;

    if (line == NULL) {
        return 0;
    }
    s = nf_strdata(line);
    len = nf_strlen(line);
    if (len >= 5 && strncasecmp(s, "HTTP/", 5) == 0) {
        return 0;
    }
    while (i < len && s[i] != ':') {
        i++;
    }
    if (i >= len) {
        return 0;
    }
    {
        size_t n = i;
        while (n > 0 && (s[n - 1] == ' ' || s[n - 1] == '\t')) {
            n--;
        }
        if (n >= bufsz) {
            n = bufsz - 1;
        }
        memcpy(buf, s, n);
        buf[n] = '\0';

        return 1;
    }
}

static int phpc_header_name_match(__string__ *line, __string__ *name)
{
    char line_name[256];
    const char *needle;
    size_t nlen;

    if (!phpc_header_name_from_line(line, line_name, sizeof line_name)) {
        return 0;
    }
    needle = nf_strdata(name);
    nlen = nf_strlen(name);

    (void) nlen;

    return 0 == strcasecmp(line_name, needle);
}

static void phpc_pending_free_nodes(void)
{
    __phpc_header_node *cur = phpc_pending_head;

    while (cur != NULL) {
        __phpc_header_node *next = cur->next;
        free(cur);
        cur = next;
    }
    phpc_pending_head = NULL;
    phpc_pending_tail = NULL;
}

void __phpc_pending_header_reset(void)
{
    phpc_pending_free_nodes();
    phpc_response_headers_flushed = 0;
}

void __phpc_response_headers_flush(void)
{
    __phpc_header_node *cur;

    if (phpc_response_headers_flushed) {
        return;
    }
    phpc_response_headers_flushed = 1;
    if (__phpc_http_response_status != 200 || __phpc_http_response_status_explicit) {
        printf("Status: %d\r\n", __phpc_http_response_status);
    }
    cur = phpc_pending_head;
    while (cur != NULL) {
        __string__ *line = cur->line;
        if (line != NULL) {
            printf("%.*s\r\n", (int) nf_strlen(line), nf_strdata(line));
        }
        cur = cur->next;
    }
}

void __phpc_pending_header_remove(__string__ *name)
{
    __phpc_header_node **pp;

    if (name == NULL || nf_strlen(name) == 0) {
        phpc_pending_free_nodes();

        return;
    }
    pp = &phpc_pending_head;
    while (*pp != NULL) {
        if (phpc_header_name_match((*pp)->line, name)) {
            __phpc_header_node *dead = *pp;
            *pp = dead->next;
            if (phpc_pending_tail == dead) {
                phpc_pending_tail = NULL;
            }
            free(dead);
        } else {
            pp = &(*pp)->next;
        }
    }
    if (phpc_pending_head == NULL) {
        phpc_pending_tail = NULL;
    }
}

void __phpc_pending_header_add(__string__ *line, int replace)
{
    __phpc_header_node *node;
    char name_buf[256];
    const char *s;
    size_t len;

    if (line == NULL) {
        return;
    }
    s = nf_strdata(line);
    len = nf_strlen(line);
    if (__phpc_http_response_status == 200 && len >= 9 && strncasecmp(s, "Location:", 9) == 0) {
        __phpc_http_response_status = 302;
    }
    if (replace && phpc_header_name_from_line(line, name_buf, sizeof name_buf)) {
        __string__ *name = cstr_to_string(name_buf);
        __phpc_pending_header_remove(name);
    }
    node = (__phpc_header_node *) malloc(sizeof(*node));
    if (node == NULL) {
        return;
    }
    node->line = line;
    node->next = NULL;
    if (phpc_pending_tail == NULL) {
        phpc_pending_head = node;
        phpc_pending_tail = node;
    } else {
        phpc_pending_tail->next = node;
        phpc_pending_tail = node;
    }
}

__hashtable__ *__phpc_pending_header_list(void)
{
    __hashtable__ *ht = __hashtable__alloc();
    __phpc_header_node *cur = phpc_pending_head;
    size_t idx = 0;

    while (cur != NULL) {
        __hashtable__setStringAt(ht, idx, cur->line);
        idx++;
        cur = cur->next;
    }

    return ht;
}
