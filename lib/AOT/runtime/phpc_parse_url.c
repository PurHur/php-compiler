/*
 * parse_url() routing subset for AOT/JIT runtime strings (mirrors ext/standard/VmString.php).
 */

#include <ctype.h>
#include <stddef.h>
#include <stdlib.h>
#include <string.h>

typedef struct __string__ __string__;
typedef struct __value__ __value__;

extern __string__ *__string__init(long long size, const char *value);
extern void __value__writeNull(__value__ *out);
extern void __value__writeLong(__value__ *out, long long v);
extern void __value__writeString(__value__ *out, __string__ *str);

#define PHP_URL_SCHEME 0
#define PHP_URL_HOST 1
#define PHP_URL_PORT 2
#define PHP_URL_PATH 5
#define PHP_URL_QUERY 6
#define PHP_URL_FRAGMENT 7

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

static void pu_write_component(__value__ *out, int component, const char *scheme,
    const char *host, int port, const char *path, const char *query, const char *fragment)
{
    switch (component) {
        case PHP_URL_SCHEME:
            if (scheme == NULL || scheme[0] == '\0') {
                __value__writeNull(out);
            } else {
                __value__writeString(out, pu_cstr(scheme));
            }

            return;
        case PHP_URL_HOST:
            if (host == NULL || host[0] == '\0') {
                __value__writeNull(out);
            } else {
                __value__writeString(out, pu_cstr(host));
            }

            return;
        case PHP_URL_PORT:
            if (port <= 0) {
                __value__writeNull(out);
            } else {
                __value__writeLong(out, (long long) port);
            }

            return;
        case PHP_URL_PATH:
            __value__writeString(out, pu_cstr(path != NULL ? path : ""));

            return;
        case PHP_URL_QUERY:
            if (query == NULL || query[0] == '\0') {
                __value__writeNull(out);
            } else {
                __value__writeString(out, pu_cstr(query));
            }

            return;
        case PHP_URL_FRAGMENT:
            if (fragment == NULL || fragment[0] == '\0') {
                __value__writeNull(out);
            } else {
                __value__writeString(out, pu_cstr(fragment));
            }

            return;
        default:
            __value__writeNull(out);

            return;
    }
}

void __phpc_parse_url_component(__string__ *url, long long component, __value__ *out)
{
    const char *input;
    size_t len;
    char *rest;
    char *scheme = NULL;
    char *host = NULL;
    int port = 0;
    char *path = NULL;
    char *query = NULL;
    char *fragment = NULL;
    size_t i;

    if (NULL == out) {
        return;
    }
    if (NULL == url) {
        __value__writeNull(out);

        return;
    }
    input = pu_strdata(url);
    len = pu_strlen(url);
    rest = pu_substr(input, 0, len);
    if (NULL == rest) {
        __value__writeNull(out);

        return;
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
            scheme = rest;
            rest = pu_strdup0(rest + i + 1);
            if (NULL == rest) {
                free(scheme);
                __value__writeNull(out);

                return;
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
                    memmove(auth_buf, at + 1, strlen(at + 1) + 1);
                }
                port_sep = strchr(auth_buf, ':');
                if (port_sep != NULL) {
                    *port_sep = '\0';
                    port = (int) strtol(port_sep + 1, NULL, 10);
                }
                host = pu_strdup0(auth_buf);
                if (end >= 0) {
                    char *tail = pu_strdup0(authority + end);
                    free(rest);
                    rest = tail;
                } else {
                    free(rest);
                    rest = pu_strdup0("");
                }
            }
        }
    }

    if (rest != NULL) {
        char *hash = strchr(rest, '#');
        if (hash != NULL) {
            *hash = '\0';
            fragment = pu_strdup0(hash + 1);
        }
        {
            char *qmark = strchr(rest, '?');
            if (qmark != NULL) {
                *qmark = '\0';
                query = pu_strdup0(qmark + 1);
            }
        }
        path = rest;
        rest = NULL;
    } else {
        path = pu_strdup0("");
    }

    pu_write_component(out, (int) component, scheme, host, port,
        path != NULL ? path : "", query, fragment);

    free(scheme);
    free(host);
    free(path);
    free(query);
    free(fragment);
}
