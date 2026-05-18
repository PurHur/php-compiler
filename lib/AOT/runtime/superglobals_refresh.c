/*
 * Runtime CGI superglobal refresh for AOT binaries (issue #201).
 * Linked with LLVM object code; reads getenv and repopulates sg_* globals.
 */

#include <stdio.h>
#include <stdlib.h>
#include <string.h>

typedef struct __hashtable__ __hashtable__;
typedef struct __string__ __string__;

extern __hashtable__ *__hashtable__alloc(void);
extern void __hashtable__setStringKeyString(__hashtable__ *ht, __string__ *key, __string__ *val);
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
        if (NULL != eq) {
            *eq = '\0';
            set_string_key(ht, pair, eq + 1);
        } else if ('\0' != pair[0]) {
            set_string_key(ht, pair, "");
        }
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
