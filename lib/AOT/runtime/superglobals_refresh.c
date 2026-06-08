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
extern void __hashtable__setLongAt(__hashtable__ *ht, size_t index, long long val);
extern void __hashtable__setDoubleAt(__hashtable__ *ht, size_t index, double val);
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

/* Bracket/delimited-pair parsing: PHP LLVM via StringParseStrJit (#7302, #6013). */
extern void __phpc_parse_str_parse_delimited_pairs(
    __hashtable__ *ht,
    const char *body,
    char delimiter,
    int decode_pair_first
);
extern __hashtable__ *__phpc_parse_str_ensure_child(__hashtable__ *ht, const char *key);

/* JSON POST body parsing: PHP LLVM via StringJsonDecodeJit (#7389). */
extern void __phpc_json_parse_post_body(__hashtable__ *ht, const char *body);

/* Multipart POST body parsing: PHP LLVM via StringMultipartJit (#7302). */
extern void __phpc_parse_multipart_post(
    __hashtable__ *post,
    __hashtable__ *files,
    const char *content_type,
    const char *body
);

#define SG_MAX_BODY (8 * 1024 * 1024)

#define SG_MULTIPART_MAX_BODY SG_MAX_BODY

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

static void parse_form_encoded(__hashtable__ *ht, const char *body)
{
    __phpc_parse_str_parse_delimited_pairs(ht, body, '&', 0);
}

static void populate_post_body(__hashtable__ *ht, const char *content_type, const char *body)
{
    if (0 == strcmp(content_type, "application/json")) {
        __phpc_json_parse_post_body(ht, body);
    } else if (0 == strncmp(content_type, "multipart/form-data", 19)) {
        __phpc_parse_multipart_post(ht, sg_FILES, content_type, body);
    } else {
        parse_form_encoded(ht, body);
    }
}

static void parse_cookie_header(__hashtable__ *ht, const char *header)
{
    __phpc_parse_str_parse_delimited_pairs(ht, header, ';', 1);
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

/* __compiler_sprintf/__compiler_printf/__compiler_number_format: LLVM in lib/JIT/Builtin/StringFormatJit.php (#1492). */

/* __compiler_strip_tags: LLVM in lib/JIT/Builtin/StringStripTags.php. */

/* __compiler_trigger_error / undefined_array_key_warning: LLVM in lib/JIT/Builtin/StringTriggerErrorJit.php (#7597). */