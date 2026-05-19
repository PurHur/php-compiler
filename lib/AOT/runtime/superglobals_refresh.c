/*
 * Runtime CGI superglobal refresh for AOT binaries (issue #201).
 * Linked with LLVM object code; reads getenv and repopulates sg_* globals.
 */

#include <stdio.h>
#include <stdlib.h>
#include <string.h>

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

static void parse_form_encoded(__hashtable__ *ht, const char *body)
{
    char *copy;
    char *pair;
    char *saveptr;

    if (NULL == body || '\0' == body[0]) {
        return;
    }

    copy = strdup(body);
    if (NULL == copy) {
        return;
    }

    pair = strtok_r(copy, "&", &saveptr);
    while (NULL != pair) {
        char *eq = strchr(pair, '=');
        char *raw_key;
        char *raw_val;
        sg_parsed_key pk;

        if (NULL != eq) {
            *eq = '\0';
            raw_key = pair;
            raw_val = eq + 1;
        } else {
            raw_key = pair;
            raw_val = (char *) "";
        }
        if ('\0' == raw_key[0]) {
            pair = strtok_r(NULL, "&", &saveptr);
            continue;
        }
        sg_url_decode_inplace(raw_key);
        sg_url_decode_inplace(raw_val);
        if (0 == sg_parse_key_brackets(raw_key, &pk)) {
            sg_set_nested_value(ht, &pk, raw_val);
        } else {
            set_string_key(ht, raw_key, raw_val);
        }
        sg_free_parsed_key(&pk);
        pair = strtok_r(NULL, "&", &saveptr);
    }

    free(copy);
}

static const char *env_or_empty(const char *name)
{
    const char *v = getenv(name);

    return NULL != v ? v : "";
}

static const char *request_method_for(const char *post_body)
{
    const char *method = getenv("REQUEST_METHOD");

    if (NULL != method && '\0' != method[0]) {
        return method;
    }

    return ('\0' != post_body[0]) ? "POST" : "GET";
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
    const char *post_body = env_or_empty("REQUEST_BODY");
    const char *method = request_method_for(post_body);
    const char *script_name = env_or_empty("SCRIPT_NAME");
    const char *request_uri = getenv("REQUEST_URI");
    char path_info[512];
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

    sg_POST = __hashtable__alloc();
    parse_form_encoded(sg_POST, post_body);

    sg_REQUEST = __hashtable__alloc();
    parse_form_encoded(sg_REQUEST, query_string);
    parse_form_encoded(sg_REQUEST, post_body);

    sg_SERVER = __hashtable__alloc();
    set_string_key(sg_SERVER, "REQUEST_METHOD", method);
    set_string_key(sg_SERVER, "QUERY_STRING", query_string);
    set_string_key(sg_SERVER, "SCRIPT_NAME", script_name);
    set_string_key(sg_SERVER, "PHP_SELF", script_name);
    set_string_key(sg_SERVER, "REQUEST_URI", request_uri);
    set_string_key(sg_SERVER, "GATEWAY_INTERFACE", "CGI/1.1");
    set_string_key(sg_SERVER, "SERVER_SOFTWARE", "PHP-Compiler-AOT");

    derive_path_info(script_name, request_uri, path_info, sizeof(path_info));
    if ('\0' != path_info[0]) {
        set_string_key(sg_SERVER, "PATH_INFO", path_info);
    }

    apply_cgi_headers_from_environ(sg_SERVER);
    apply_scheme_and_port(sg_SERVER);

    if (NULL == sg_COOKIE) {
        sg_COOKIE = __hashtable__alloc();
    }
    if (NULL == sg_ENV) {
        sg_ENV = __hashtable__alloc();
    }
    if (NULL == sg_FILES) {
        sg_FILES = __hashtable__alloc();
    }
    if (NULL == sg_SESSION) {
        sg_SESSION = __hashtable__alloc();
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
