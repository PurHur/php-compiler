/*
 * parse_url() routing subset for AOT/JIT runtime strings (mirrors ext/standard/VmString.php).
 */

#include <ctype.h>
#include <stddef.h>
#include <stdlib.h>
#include <string.h>

typedef struct __string__ __string__;
typedef struct __value__ __value__;
typedef struct __hashtable__ __hashtable__;

extern __string__ *__string__init(long long size, const char *value);
extern void __value__writeNull(__value__ *out);
extern void __value__writeLong(__value__ *out, long long v);
extern void __value__writeString(__value__ *out, __string__ *str);
extern void __value__writeHashtable(__value__ *out, __hashtable__ *ht);
extern __hashtable__ *__hashtable__alloc(void);
extern void __hashtable__setStringKeyString(__hashtable__ *ht, __string__ *key, __string__ *val);
extern void __hashtable__setStringKeyLong(__hashtable__ *ht, __string__ *key, long long val);

#define PHP_URL_SCHEME 0
#define PHP_URL_HOST 1
#define PHP_URL_PORT 2
#define PHP_URL_USER 3
#define PHP_URL_PASS 4
#define PHP_URL_PATH 5
#define PHP_URL_QUERY 6
#define PHP_URL_FRAGMENT 7

typedef struct {
    char *scheme;
    char *host;
    int port;
    char *user;
    char *pass;
    char *path;
    char *query;
    char *fragment;
} pu_parts_t;

static size_t pu_strlen(__string__ *s)
{
    if (NULL == s) {
        return 0;
    }

    return (size_t) *((long long *) ((char *) s + sizeof(void *)));
}

static const char *pu_strdata(__string__ *s)
{
    if (NULL == s) {
        return "";
    }

    return (const char *) s + sizeof(void *) + sizeof(long long);
}

static __string__ *pu_cstr(const char *cstr)
{
    return __string__init((long long) strlen(cstr), cstr);
}

static int pu_is_scheme_char(unsigned char ch)
{
    return isalpha(ch) || isdigit(ch) || ch == '+' || ch == '.' || ch == '-';
}

static int pu_min_pos(int a, int b)
{
    if (a < 0) {
        return b;
    }
    if (b < 0) {
        return a;
    }

    return a < b ? a : b;
}

static int pu_min_pos3(int a, int b, int c)
{
    return pu_min_pos(pu_min_pos(a, b), c);
}

static char *pu_strdup0(const char *src)
{
    size_t len = strlen(src);
    char *out = (char *) malloc(len + 1);

    if (NULL == out) {
        return NULL;
    }
    memcpy(out, src, len);
    out[len] = '\0';

    return out;
}

static char *pu_substr(const char *src, size_t off, size_t n)
{
    size_t len = strlen(src);
    char *out;

    if (off >= len) {
        return pu_strdup0("");
    }
    if (off + n > len) {
        n = len - off;
    }
    out = (char *) malloc(n + 1);
    if (NULL == out) {
        return NULL;
    }
    memcpy(out, src + off, n);
    out[n] = '\0';

    return out;
}

static void pu_parts_init(pu_parts_t *parts)
{
    parts->scheme = NULL;
    parts->host = NULL;
    parts->port = 0;
    parts->user = NULL;
    parts->pass = NULL;
    parts->path = NULL;
    parts->query = NULL;
    parts->fragment = NULL;
}

static void pu_parts_free(pu_parts_t *parts)
{
    free(parts->scheme);
    free(parts->host);
    free(parts->user);
    free(parts->pass);
    free(parts->path);
    free(parts->query);
    free(parts->fragment);
    pu_parts_init(parts);
}

static int pu_parse_parts(__string__ *url, pu_parts_t *parts)
{
    const char *input;
    size_t len;
    char *rest;
    size_t i;

    pu_parts_init(parts);
    if (NULL == url) {
        return -1;
    }
    input = pu_strdata(url);
    len = pu_strlen(url);
    rest = pu_substr(input, 0, len);
    if (NULL == rest) {
        return -1;
    }

    if (len >= 2 && isalpha((unsigned char) rest[0])) {
        i = 0;
        while (rest[i] != '\0' && rest[i] != ':') {
            if (!pu_is_scheme_char((unsigned char) rest[i])) {
                break;
            }
            i++;
        }
        if (rest[i] == ':') {
            rest[i] = '\0';
            parts->scheme = rest;
            rest = pu_strdup0(rest + i + 1);
            if (NULL == rest) {
                return -1;
            }
            if (strncmp(rest, "//", 2) == 0) {
                char *authority = rest + 2;
                char *slash = strchr(authority, '/');
                char *qmark = strchr(authority, '?');
                char *hash = strchr(authority, '#');
                int end = pu_min_pos3(
                    slash != NULL ? (int) (slash - authority) : -1,
                    qmark != NULL ? (int) (qmark - authority) : -1,
                    hash != NULL ? (int) (hash - authority) : -1
                );
                char auth_buf[512];
                size_t auth_len;
                char *at;
                char *port_sep;

                if (end >= 0) {
                    auth_len = (size_t) end;
                } else {
                    auth_len = strlen(authority);
                }
                if (auth_len >= sizeof auth_buf) {
                    auth_len = sizeof auth_buf - 1;
                }
                memcpy(auth_buf, authority, auth_len);
                auth_buf[auth_len] = '\0';
                at = strrchr(auth_buf, '@');
                if (at != NULL) {
                    char *colon;
                    *at = '\0';
                    colon = strchr(auth_buf, ':');
                    if (colon != NULL) {
                        *colon = '\0';
                        parts->pass = pu_strdup0(colon + 1);
                        if (NULL == parts->pass) {
                            pu_parts_free(parts);

                            return -1;
                        }
                    }
                    if (auth_buf[0] != '\0') {
                        parts->user = pu_strdup0(auth_buf);
                        if (NULL == parts->user) {
                            pu_parts_free(parts);

                            return -1;
                        }
                    }
                    memmove(auth_buf, at + 1, strlen(at + 1) + 1);
                }
                port_sep = strchr(auth_buf, ':');
                if (port_sep != NULL) {
                    *port_sep = '\0';
                    parts->port = (int) strtol(port_sep + 1, NULL, 10);
                }
                parts->host = pu_strdup0(auth_buf);
                if (NULL == parts->host) {
                    pu_parts_free(parts);

                    return -1;
                }
                if (end >= 0) {
                    char *tail = pu_strdup0(authority + end);
                    free(rest);
                    rest = tail;
                } else {
                    free(rest);
                    rest = pu_strdup0("");
                }
                if (NULL == rest) {
                    pu_parts_free(parts);

                    return -1;
                }
            }
        }
    }

    if (rest != NULL) {
        char *hash = strchr(rest, '#');
        if (hash != NULL) {
            *hash = '\0';
            parts->fragment = pu_strdup0(hash + 1);
            if (NULL == parts->fragment) {
                pu_parts_free(parts);
                free(rest);

                return -1;
            }
        }
        {
            char *qmark = strchr(rest, '?');
            if (qmark != NULL) {
                *qmark = '\0';
                parts->query = pu_strdup0(qmark + 1);
                if (NULL == parts->query) {
                    pu_parts_free(parts);
                    free(rest);

                    return -1;
                }
            }
        }
        parts->path = rest;
        rest = NULL;
    } else {
        parts->path = pu_strdup0("");
        if (NULL == parts->path) {
            pu_parts_free(parts);

            return -1;
        }
    }

    return 0;
}

static void pu_write_component(__value__ *out, int component, pu_parts_t *parts)
{
    switch (component) {
        case PHP_URL_SCHEME:
            if (parts->scheme == NULL || parts->scheme[0] == '\0') {
                __value__writeNull(out);
            } else {
                __value__writeString(out, pu_cstr(parts->scheme));
            }

            return;
        case PHP_URL_HOST:
            if (parts->host == NULL || parts->host[0] == '\0') {
                __value__writeNull(out);
            } else {
                __value__writeString(out, pu_cstr(parts->host));
            }

            return;
        case PHP_URL_PORT:
            if (parts->port <= 0) {
                __value__writeNull(out);
            } else {
                __value__writeLong(out, (long long) parts->port);
            }

            return;
        case PHP_URL_USER:
            if (parts->user == NULL || parts->user[0] == '\0') {
                __value__writeNull(out);
            } else {
                __value__writeString(out, pu_cstr(parts->user));
            }

            return;
        case PHP_URL_PASS:
            if (parts->pass == NULL || parts->pass[0] == '\0') {
                __value__writeNull(out);
            } else {
                __value__writeString(out, pu_cstr(parts->pass));
            }

            return;
        case PHP_URL_PATH:
            __value__writeString(out, pu_cstr(parts->path != NULL ? parts->path : ""));

            return;
        case PHP_URL_QUERY:
            if (parts->query == NULL || parts->query[0] == '\0') {
                __value__writeNull(out);
            } else {
                __value__writeString(out, pu_cstr(parts->query));
            }

            return;
        case PHP_URL_FRAGMENT:
            if (parts->fragment == NULL || parts->fragment[0] == '\0') {
                __value__writeNull(out);
            } else {
                __value__writeString(out, pu_cstr(parts->fragment));
            }

            return;
        default:
            __value__writeNull(out);

            return;
    }
}

static void pu_maybe_set_string(__hashtable__ *ht, const char *key, const char *value)
{
    if (value == NULL || value[0] == '\0') {
        return;
    }
    __hashtable__setStringKeyString(ht, pu_cstr(key), pu_cstr(value));
}

void __phpc_parse_url_component(__string__ *url, long long component, __value__ *out)
{
    pu_parts_t parts;

    if (NULL == out) {
        return;
    }
    if (0 != pu_parse_parts(url, &parts)) {
        __value__writeNull(out);

        return;
    }
    pu_write_component(out, (int) component, &parts);
    pu_parts_free(&parts);
}

void __phpc_parse_url_assoc(__string__ *url, __value__ *out)
{
    pu_parts_t parts;
    __hashtable__ *ht;

    if (NULL == out) {
        return;
    }
    if (0 != pu_parse_parts(url, &parts)) {
        __value__writeNull(out);

        return;
    }
    ht = __hashtable__alloc();
    if (NULL == ht) {
        pu_parts_free(&parts);
        __value__writeNull(out);

        return;
    }
    pu_maybe_set_string(ht, "scheme", parts.scheme);
    pu_maybe_set_string(ht, "host", parts.host);
    if (parts.port > 0) {
        __hashtable__setStringKeyLong(ht, pu_cstr("port"), (long long) parts.port);
    }
    pu_maybe_set_string(ht, "user", parts.user);
    pu_maybe_set_string(ht, "pass", parts.pass);
    pu_maybe_set_string(ht, "path", parts.path);
    pu_maybe_set_string(ht, "query", parts.query);
    pu_maybe_set_string(ht, "fragment", parts.fragment);
    __value__writeHashtable(out, ht);
    pu_parts_free(&parts);
}
