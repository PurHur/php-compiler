/*
 * Pending response headers + setcookie queue for JIT/AOT (issues #311, #1170).
 */

#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <time.h>

typedef struct __hashtable__ __hashtable__;
typedef struct __string__ __string__;

extern __hashtable__ *__hashtable__alloc(void);
extern void __hashtable__setStringAt(__hashtable__ *ht, size_t index, __string__ *val);
extern __string__ *__string__init(long long size, const char *value);

extern int __phpc_http_response_status;
extern int __phpc_http_response_status_explicit;

void __phpc_pending_header_add(__string__ *line, int replace);

static __string__ *cstr_to_string(const char *cstr)
{
    size_t len = strlen(cstr);

    return __string__init((long long) len, cstr);
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

static void nf_append_str(char *buf, size_t *pos, size_t cap, const char *src, size_t len)
{
    size_t i;

    for (i = 0; i < len && *pos + 1 < cap; i++) {
        buf[(*pos)++] = src[i];
    }
}

typedef struct __phpc_header_node {
    __string__ *line;
    struct __phpc_header_node *next;
} __phpc_header_node;

static __phpc_header_node *phpc_pending_head = NULL;
static __phpc_header_node *phpc_pending_tail = NULL;
static int phpc_response_headers_flushed = 0;

extern int __phpc_sapi_output_started;

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

static int phpc_header_line_has_crlf(__string__ *line)
{
    const char *s;
    size_t len;
    size_t i;

    if (line == NULL) {
        return 0;
    }
    s = nf_strdata(line);
    len = nf_strlen(line);
    for (i = 0; i < len; i++) {
        if (s[i] == '\r' || s[i] == '\n') {
            return 1;
        }
    }

    return 0;
}

static int phpc_header_name_match(__string__ *line, __string__ *name)
{
    char line_name[256];

    if (!phpc_header_name_from_line(line, line_name, sizeof line_name)) {
        return 0;
    }

    return 0 == strcasecmp(line_name, nf_strdata(name));
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
    __phpc_sapi_output_started = 0;
}

int __phpc_headers_sent(void)
{
    return __phpc_sapi_output_started || phpc_response_headers_flushed;
}

void __phpc_response_headers_flush(void)
{
    __phpc_header_node *cur;
    int wrote_headers = 0;

    if (phpc_response_headers_flushed) {
        return;
    }
    phpc_response_headers_flushed = 1;
    if (__phpc_http_response_status != 200 || __phpc_http_response_status_explicit) {
        printf("Status: %d\r\n", __phpc_http_response_status);
        wrote_headers = 1;
    }
    cur = phpc_pending_head;
    while (cur != NULL) {
        __string__ *line = cur->line;
        if (line != NULL) {
            printf("%.*s\r\n", (int) nf_strlen(line), nf_strdata(line));
            wrote_headers = 1;
        }
        cur = cur->next;
    }
    if (wrote_headers) {
        printf("\r\n");
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

static void phpc_setcookie_append_expires(char *buf, size_t *pos, size_t cap, int64_t expires)
{
    static const char *wd[] = {"Sun", "Mon", "Tue", "Wed", "Thu", "Fri", "Sat"};
    static const char *mo[] = {
        "Jan", "Feb", "Mar", "Apr", "May", "Jun",
        "Jul", "Aug", "Sep", "Oct", "Nov", "Dec",
    };
    time_t t;
    struct tm tm;
    char chunk[80];

    if (expires <= 0) {
        return;
    }
    t = (time_t) expires;
#if defined(_POSIX_VERSION) && _POSIX_VERSION >= 200112L
    if (gmtime_r(&t, &tm) == NULL) {
        return;
    }
#else
    {
        struct tm *ptm = gmtime(&t);

        if (ptm == NULL) {
            return;
        }
        tm = *ptm;
    }
#endif
    snprintf(
        chunk,
        sizeof chunk,
        "; expires=%s, %02d-%s-%04d %02d:%02d:%02d GMT",
        wd[tm.tm_wday % 7],
        tm.tm_mday,
        mo[tm.tm_mon % 12],
        tm.tm_year + 1900,
        tm.tm_hour,
        tm.tm_min,
        tm.tm_sec
    );
    nf_append_str(buf, pos, cap, chunk, strlen(chunk));
}

void __phpc_setcookie_add(
    __string__ *name,
    __string__ *value,
    int64_t expires,
    __string__ *path,
    __string__ *domain,
    int secure,
    int httponly
)
{
    char buf[2048];
    size_t pos = 0;
    const char *name_s;
    size_t name_len;
    const char *value_s;
    size_t value_len;
    const char *path_s;
    size_t path_len;
    const char *domain_s;
    size_t domain_len;
    __string__ *line;

    if (name == NULL) {
        return;
    }
    name_s = nf_strdata(name);
    name_len = nf_strlen(name);
    value_s = value != NULL ? nf_strdata(value) : "";
    value_len = value != NULL ? nf_strlen(value) : 0;
    path_s = path != NULL ? nf_strdata(path) : "";
    path_len = path != NULL ? nf_strlen(path) : 0;
    domain_s = domain != NULL ? nf_strdata(domain) : "";
    domain_len = domain != NULL ? nf_strlen(domain) : 0;

    nf_append_str(buf, &pos, sizeof buf, "Set-Cookie: ", 12);
    nf_append_str(buf, &pos, sizeof buf, name_s, name_len);
    nf_append_str(buf, &pos, sizeof buf, "=", 1);
    nf_append_str(buf, &pos, sizeof buf, value_s, value_len);
    phpc_setcookie_append_expires(buf, &pos, sizeof buf, expires);
    if (path_len > 0) {
        nf_append_str(buf, &pos, sizeof buf, "; path=", 7);
        nf_append_str(buf, &pos, sizeof buf, path_s, path_len);
    }
    if (domain_len > 0) {
        nf_append_str(buf, &pos, sizeof buf, "; domain=", 9);
        nf_append_str(buf, &pos, sizeof buf, domain_s, domain_len);
    }
    if (secure) {
        nf_append_str(buf, &pos, sizeof buf, "; secure", 8);
    }
    if (httponly) {
        nf_append_str(buf, &pos, sizeof buf, "; httponly", 10);
    }
    if (pos >= sizeof buf) {
        pos = sizeof buf - 1;
    }
    buf[pos] = '\0';
    line = cstr_to_string(buf);
    __phpc_pending_header_add(line, 0);
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
    if (phpc_header_line_has_crlf(line)) {
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
