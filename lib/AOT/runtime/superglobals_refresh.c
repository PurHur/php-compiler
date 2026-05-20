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

static void parse_cookie_header(__hashtable__ *ht, const char *header)
{
    parse_delimited_pairs(ht, header, ';', 1);
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
    const char *post_body = env_or_empty("REQUEST_BODY");
    const char *method = request_method_for(post_body);
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

    sg_POST = __hashtable__alloc();
    if ('\0' != post_body[0]) {
        parse_form_encoded(sg_POST, post_body);
    }

    sg_REQUEST = __hashtable__alloc();
    if ('\0' != query_string[0]) {
        parse_form_encoded(sg_REQUEST, query_string);
    }
    if ('\0' != post_body[0]) {
        parse_form_encoded(sg_REQUEST, post_body);
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

    derive_path_info(script_name, request_uri, path_info, sizeof(path_info));
    if ('\0' != path_info[0]) {
        set_string_key(sg_SERVER, "PATH_INFO", path_info);
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
