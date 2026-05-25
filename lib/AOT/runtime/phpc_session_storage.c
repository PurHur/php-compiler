/*
 * File-backed $_SESSION for standalone AOT CGI (issues #1938, #1891).
 * Uses PHP serialize format compatible with VM VmSession (string keys/values).
 */

#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <stdint.h>
#include <sys/stat.h>

typedef struct __hashtable__ __hashtable__;
typedef struct __string__ __string__;
typedef struct __value__ __value__;
typedef struct __strkey_node__ __strkey_node__;

extern __hashtable__ *sg_SESSION;

extern char __phpc_session_id_storage[];
extern char __phpc_session_name_storage[];
extern int64_t __phpc_session_id_len;
extern int64_t __phpc_session_name_len;
extern char __phpc_session_active;

extern __hashtable__ *__hashtable__alloc(void);
extern void __hashtable__setStringKeyString(__hashtable__ *ht, __string__ *key, __string__ *val);
extern __string__ *__string__init(long long size, const char *value);
extern void __compiler_unserialize(__string__ *payload, __value__ *out);
extern void __phpc_setcookie_add(
    __string__ *name,
    __string__ *value,
    int64_t expires,
    __string__ *path,
    __string__ *domain,
    int secure,
    int httponly
);

#define PHPC_TYPE_STRING 132
#define PHPC_TYPE_HASHTABLE 135
#define PHPC_SESSION_ID_MAX 128
#define PHPC_SESSION_NAME_MAX 128
#define PHPC_SESSION_FILE_MAX (1024 * 1024)
#define PHPC_SESSION_SER_CAP 65536

typedef struct __value__ {
    signed char type;
    char value[8];
} __value__;

typedef struct __strkey_node__ {
    void *ref;
    __string__ *key;
    __value__ value;
    __strkey_node__ *next;
} __strkey_node__;

typedef struct __hashtable__ {
    void *ref;
    size_t numElements;
    size_t nextFreeElement;
    size_t capacity;
    void *values;
    __strkey_node__ *strKeys;
    void *objKeys;
} __hashtable__;

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

static __string__ *cstr_to_string(const char *cstr)
{
    return __string__init((long long) strlen(cstr), cstr);
}

static int phpc_type_base(signed char type)
{
    return (int) type & 127;
}

static __string__ *phpc_value_as_string(__value__ *val)
{
    if (NULL == val || phpc_type_base(val->type) != 4) {
        return NULL;
    }

    return *(__string__ **) val->value;
}

static __hashtable__ *phpc_value_as_hashtable(__value__ *val)
{
    if (NULL == val || phpc_type_base(val->type) != 7) {
        return NULL;
    }

    return *(__hashtable__ **) val->value;
}

static void phpc_append_bytes(char *buf, size_t *pos, size_t cap, const char *src, size_t len)
{
    size_t i;

    for (i = 0; i < len && *pos + 1 < cap; i++) {
        buf[(*pos)++] = src[i];
    }
}

static void phpc_append_cstr(char *buf, size_t *pos, size_t cap, const char *src)
{
    phpc_append_bytes(buf, pos, cap, src, strlen(src));
}

static void phpc_append_ulong(char *buf, size_t *pos, size_t cap, unsigned long n)
{
    char tmp[32];
    int i = 0;
    int j;

    if (0 == n) {
        phpc_append_cstr(buf, pos, cap, "0");

        return;
    }
    while (n > 0) {
        tmp[i++] = (char) ('0' + (n % 10));
        n /= 10;
    }
    for (j = i - 1; j >= 0 && *pos + 1 < cap; j--) {
        buf[(*pos)++] = tmp[j];
    }
}

static int phpc_sanitize_session_id(const char *id, char *out, size_t out_cap)
{
    size_t i = 0;
    size_t o = 0;

    if (NULL == id) {
        return 0;
    }
    while ('\0' != id[i] && o + 1 < out_cap) {
        char c = id[i++];
        if ((c >= 'a' && c <= 'z')
            || (c >= 'A' && c <= 'Z')
            || (c >= '0' && c <= '9')
            || c == ','
            || c == '-') {
            out[o++] = c;
        }
    }
    out[o] = '\0';

    return o > 0;
}

static int phpc_cookie_value_for_name(const char *header, const char *name, char *out, size_t out_cap)
{
    size_t name_len;
    const char *p;
    const char *end;
    size_t o = 0;

    if (NULL == header || NULL == name || '\0' == name[0]) {
        return 0;
    }
    name_len = strlen(name);
    p = header;
    while ('\0' != *p) {
        while (' ' == *p || '\t' == *p) {
            p++;
        }
        if (0 != strncmp(p, name, name_len) || '=' != p[name_len]) {
            while ('\0' != *p && ';' != *p) {
                p++;
            }
            if (';' == *p) {
                p++;
            }
            continue;
        }
        p += name_len + 1;
        end = strchr(p, ';');
        if (NULL == end) {
            end = p + strlen(p);
        }
        while (p < end && o + 1 < out_cap) {
            out[o++] = *p++;
        }
        out[o] = '\0';

        return o > 0;
    }

    return 0;
}

static const char *phpc_session_dir(void)
{
    const char *dir = getenv("PHP_COMPILER_SESSION_DIR");

    if (NULL != dir && '\0' != dir[0]) {
        return dir;
    }

    return "/tmp/phpc_sessions";
}

static int phpc_session_path(char *out, size_t out_cap)
{
    const char *dir;
    int n;

    if (__phpc_session_id_len <= 0) {
        return 0;
    }
    dir = phpc_session_dir();
    n = snprintf(out, out_cap, "%s/sess_%s", dir, __phpc_session_id_storage);

    return n > 0 && (size_t) n < out_cap;
}

static void phpc_session_ensure_dir(void)
{
    const char *dir = phpc_session_dir();
    char buf[512];
    size_t len;
    size_t i;

    if (NULL == dir || '\0' == dir[0]) {
        return;
    }
    len = strlen(dir);
    if (len >= sizeof buf) {
        return;
    }
    memcpy(buf, dir, len + 1);
    for (i = 1; i < len; i++) {
        if ('/' != buf[i]) {
            continue;
        }
        buf[i] = '\0';
        if ('\0' != buf[0]) {
            (void) mkdir(buf, 0700);
        }
        buf[i] = '/';
    }
    (void) mkdir(buf, 0700);
}

static int phpc_session_emit_cookie(void)
{
    __string__ *name;
    __string__ *value;
    __string__ *path;

    if (__phpc_session_id_len <= 0 || __phpc_session_name_len <= 0) {
        return 0;
    }
    name = cstr_to_string(__phpc_session_name_storage);
    value = cstr_to_string(__phpc_session_id_storage);
    path = cstr_to_string("/");
    __phpc_setcookie_add(name, value, 0, path, NULL, 0, 0);

    return 1;
}

static int phpc_session_serialize_hashtable(__hashtable__ *ht, char *buf, size_t cap)
{
    __strkey_node__ *node;
    size_t pos = 0;
    unsigned long count = 0;

    if (NULL == ht) {
        phpc_append_cstr(buf, &pos, cap, "a:0:{}");

        return 1;
    }
    for (node = ht->strKeys; NULL != node; node = node->next) {
        if (NULL != phpc_value_as_string(&node->value)) {
            count++;
        }
    }
    phpc_append_cstr(buf, &pos, cap, "a:");
    phpc_append_ulong(buf, &pos, cap, count);
    phpc_append_cstr(buf, &pos, cap, ":{");
    for (node = ht->strKeys; NULL != node; node = node->next) {
        __string__ *val = phpc_value_as_string(&node->value);
        const char *key_data;
        const char *val_data;
        size_t key_len;
        size_t val_len;

        if (NULL == node->key || NULL == val) {
            continue;
        }
        key_data = phpc_string_data(node->key);
        key_len = phpc_string_len(node->key);
        val_data = phpc_string_data(val);
        val_len = phpc_string_len(val);
        phpc_append_cstr(buf, &pos, cap, "s:");
        phpc_append_ulong(buf, &pos, cap, (unsigned long) key_len);
        phpc_append_cstr(buf, &pos, cap, ":\"");
        phpc_append_bytes(buf, &pos, cap, key_data, key_len);
        phpc_append_cstr(buf, &pos, cap, "\";s:");
        phpc_append_ulong(buf, &pos, cap, (unsigned long) val_len);
        phpc_append_cstr(buf, &pos, cap, ":\"");
        phpc_append_bytes(buf, &pos, cap, val_data, val_len);
        phpc_append_cstr(buf, &pos, cap, "\";");
    }
    phpc_append_cstr(buf, &pos, cap, "}");
    if (pos >= cap) {
        return 0;
    }
    buf[pos] = '\0';

    return 1;
}

static void phpc_session_save_file(void)
{
    char path[512];
    char payload[PHPC_SESSION_SER_CAP];
    FILE *fp;

    if (!__phpc_session_active || __phpc_session_id_len <= 0 || NULL == sg_SESSION) {
        return;
    }
    if (!phpc_session_path(path, sizeof path)) {
        return;
    }
    if (!phpc_session_serialize_hashtable(sg_SESSION, payload, sizeof payload)) {
        return;
    }
    phpc_session_ensure_dir();
    fp = fopen(path, "wb");
    if (NULL == fp) {
        return;
    }
    fwrite(payload, 1, strlen(payload), fp);
    fclose(fp);
}

static void phpc_session_load_file(void)
{
    char path[512];
    char *raw;
    long size;
    FILE *fp;
    __value__ decoded;
    __hashtable__ *loaded;

    if (__phpc_session_id_len <= 0) {
        return;
    }
    if (!phpc_session_path(path, sizeof path)) {
        return;
    }
    fp = fopen(path, "rb");
    if (NULL == fp) {
        return;
    }
    if (0 != fseek(fp, 0, SEEK_END)) {
        fclose(fp);

        return;
    }
    size = ftell(fp);
    if (size <= 0 || size > (long) PHPC_SESSION_FILE_MAX) {
        fclose(fp);

        return;
    }
    if (0 != fseek(fp, 0, SEEK_SET)) {
        fclose(fp);

        return;
    }
    raw = (char *) malloc((size_t) size + 1);
    if (NULL == raw) {
        fclose(fp);

        return;
    }
    if (1 != fread(raw, (size_t) size, 1, fp)) {
        free(raw);
        fclose(fp);

        return;
    }
    fclose(fp);
    raw[size] = '\0';
    memset(&decoded, 0, sizeof decoded);
    __compiler_unserialize(cstr_to_string(raw), &decoded);
    free(raw);
    loaded = phpc_value_as_hashtable(&decoded);
    if (NULL != loaded) {
        sg_SESSION = loaded;
    }
}

static void phpc_session_apply_cookie_id(void)
{
    const char *cookie_hdr;
    char id_buf[PHPC_SESSION_ID_MAX + 1];
    char name_buf[PHPC_SESSION_NAME_MAX + 1];
    size_t name_len;

    cookie_hdr = getenv("HTTP_COOKIE");
    if (NULL == cookie_hdr || '\0' == cookie_hdr[0]) {
        return;
    }
    if (__phpc_session_name_len <= 0) {
        memcpy(__phpc_session_name_storage, "PHPSESSID", 9);
        __phpc_session_name_storage[9] = '\0';
        __phpc_session_name_len = 9;
    }
    name_len = (size_t) __phpc_session_name_len;
    if (name_len >= sizeof name_buf) {
        name_len = sizeof name_buf - 1;
    }
    memcpy(name_buf, __phpc_session_name_storage, name_len);
    name_buf[name_len] = '\0';
    if (!phpc_cookie_value_for_name(cookie_hdr, name_buf, id_buf, sizeof id_buf)) {
        return;
    }
    if (!phpc_sanitize_session_id(id_buf, __phpc_session_id_storage, PHPC_SESSION_ID_MAX + 1)) {
        return;
    }
    __phpc_session_id_len = (int64_t) strlen(__phpc_session_id_storage);
}

void __phpc_session_storage_on_start(void)
{
    int created_id = 0;

    phpc_session_apply_cookie_id();
    if (__phpc_session_id_len <= 0) {
        extern void __phpc_session_generate_new_id(void);
        __phpc_session_generate_new_id();
        created_id = 1;
    }
    if (NULL == sg_SESSION) {
        sg_SESSION = __hashtable__alloc();
    }
    phpc_session_load_file();
    if (created_id) {
        phpc_session_emit_cookie();
    }
}

void __phpc_session_storage_on_write_close(void)
{
    phpc_session_save_file();
}

void __phpc_session_shutdown_persist(void)
{
    if (__phpc_session_active) {
        phpc_session_save_file();
    }
}
