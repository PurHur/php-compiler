/*
 * File-backed $_SESSION for AOT/CGI (issues #64, #1938).
 * Uses PHP serialize format compatible with VM VmSession.
 */

#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <stdint.h>
#include <sys/stat.h>

typedef struct __hashtable__ __hashtable__;
typedef struct __string__ __string__;
typedef struct __value__ __value__;

#define PHPC_SESSION_ID_MAX 128
#define PHPC_SESSION_NAME_MAX 128
#define PHPC_SESSION_ENCODE_CAP (256 * 1024)

#define PHPC_TYPE_NULL 0
#define PHPC_TYPE_NATIVE_LONG 1
#define PHPC_TYPE_NATIVE_BOOL 2
#define PHPC_TYPE_STRING 4

extern char __phpc_session_id_storage[PHPC_SESSION_ID_MAX + 1];
extern char __phpc_session_name_storage[PHPC_SESSION_NAME_MAX + 1];
extern int64_t __phpc_session_id_len;
extern int64_t __phpc_session_name_len;

extern __hashtable__ *sg_SESSION;

extern __hashtable__ *__hashtable__alloc(void);
extern __string__ *__string__init(long long size, const char *value);
extern __hashtable__ *phpc_session_decode_payload(const char *body, size_t len);
extern void __hashtable__setStringKeyString(__hashtable__ *ht, __string__ *key, __string__ *val);
extern void __hashtable__setStringKeyLong(__hashtable__ *ht, __string__ *key, long long val);
extern void __hashtable__setStringKeyBool(__hashtable__ *ht, __string__ *key, int val);

extern void __phpc_setcookie_add(
    __string__ *name,
    __string__ *value,
    int64_t expires,
    __string__ *path,
    __string__ *domain,
    int secure,
    int httponly
);

typedef struct __ref__ {
    int32_t refcount;
    int32_t typeinfo;
} __ref__;

typedef struct __value__ {
    int8_t type;
    int8_t value[8];
} __value__;

typedef struct __strkey_node__ {
    __ref__ ref;
    __string__ *key;
    __value__ value;
    struct __strkey_node__ *next;
} __strkey_node__;

typedef struct __hashtable__ {
    __ref__ ref;
    size_t numElements;
    size_t nextFreeElement;
    size_t capacity;
    __value__ *values;
    __strkey_node__ *strKeys;
    void *objKeys;
} __hashtable__;

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

static __string__ *cstr_to_string(const char *cstr)
{
    return __string__init((long long) strlen(cstr), cstr);
}

static int phpc_session_sanitize_id(const char *raw, char *out, size_t out_cap)
{
    size_t i;
    size_t n = 0;

    if (NULL == raw || out_cap < 2) {
        return 0;
    }
    for (i = 0; raw[i] != '\0' && n + 1 < out_cap; i++) {
        char ch = raw[i];
        if (
            (ch >= 'a' && ch <= 'z')
            || (ch >= 'A' && ch <= 'Z')
            || (ch >= '0' && ch <= '9')
            || ch == ','
            || ch == '-'
        ) {
            out[n++] = ch;
        }
    }
    out[n] = '\0';

    return n > 0;
}

static const char *phpc_session_storage_dir(void)
{
    const char *from_env = getenv("PHP_COMPILER_SESSION_DIR");

    if (NULL != from_env && '\0' != from_env[0]) {
        return from_env;
    }

    return "/tmp/phpc_sessions";
}

static int phpc_session_storage_path(const char *id, char *path, size_t cap)
{
    const char *dir = phpc_session_storage_dir();
    int n;

    n = snprintf(path, cap, "%s/sess_%s", dir, id);

    return n > 0 && (size_t) n < cap;
}

static int phpc_session_read_cookie_id(char *out_id, size_t out_cap)
{
    const char *header;
    const char *name;
    size_t name_len;
    const char *cursor;
    char pair[512];
    size_t pair_len;

    if (__phpc_session_name_len <= 0) {
        return 0;
    }
    header = getenv("HTTP_COOKIE");
    if (NULL == header || '\0' == header[0]) {
        return 0;
    }
    name = __phpc_session_name_storage;
    name_len = (size_t) __phpc_session_name_len;
    cursor = header;
    while (*cursor != '\0') {
        const char *end;
        const char *eq;
        const char *val;

        while (*cursor == ' ' || *cursor == ';') {
            cursor++;
        }
        if ('\0' == *cursor) {
            break;
        }
        end = strchr(cursor, ';');
        if (NULL == end) {
            end = cursor + strlen(cursor);
        }
        pair_len = (size_t) (end - cursor);
        if (pair_len >= sizeof pair) {
            pair_len = sizeof pair - 1;
        }
        memcpy(pair, cursor, pair_len);
        pair[pair_len] = '\0';
        eq = strchr(pair, '=');
        if (NULL == eq) {
            cursor = (*end == '\0') ? end : end + 1;
            continue;
        }
        val = eq + 1;
        if ((size_t) (eq - pair) == name_len && 0 == strncmp(pair, name, name_len)) {
            return phpc_session_sanitize_id(val, out_id, out_cap);
        }
        cursor = (*end == '\0') ? end : end + 1;
    }

    return 0;
}

void phpc_session_emit_setcookie(void)
{
    __string__ *name;
    __string__ *value;
    __string__ *path;

    if (__phpc_session_id_len <= 0) {
        return;
    }
    name = cstr_to_string(__phpc_session_name_storage);
    value = cstr_to_string(__phpc_session_id_storage);
    path = cstr_to_string("/");
    __phpc_setcookie_add(name, value, 0, path, NULL, 0, 0);
}

static int phpc_session_append_str(char *buf, size_t *pos, size_t cap, const char *chunk, size_t chunk_len)
{
    if (*pos + chunk_len >= cap) {
        return 0;
    }
    memcpy(buf + *pos, chunk, chunk_len);
    *pos += chunk_len;

    return 1;
}

static int phpc_session_append_decimal(char *buf, size_t *pos, size_t cap, unsigned long n)
{
    char tmp[32];
    int len = snprintf(tmp, sizeof tmp, "%lu", n);

    if (len <= 0) {
        return 0;
    }

    return phpc_session_append_str(buf, pos, cap, tmp, (size_t) len);
}

static int phpc_session_encode_quoted_string(char *buf, size_t *pos, size_t cap, __string__ *str)
{
    size_t len = nf_strlen(str);
    const char *data = nf_strdata(str);

    if (!phpc_session_append_str(buf, pos, cap, "s:", 2)) {
        return 0;
    }
    if (!phpc_session_append_decimal(buf, pos, cap, (unsigned long) len)) {
        return 0;
    }
    if (!phpc_session_append_str(buf, pos, cap, ":\"", 2)) {
        return 0;
    }
    if (!phpc_session_append_str(buf, pos, cap, data, len)) {
        return 0;
    }

    return phpc_session_append_str(buf, pos, cap, "\";", 2);
}

static int phpc_session_encode_value(char *buf, size_t *pos, size_t cap, const __value__ *val)
{
    int8_t type = val->type;

    if (PHPC_TYPE_NULL == type) {
        return phpc_session_append_str(buf, pos, cap, "N;", 2);
    }
    if (PHPC_TYPE_NATIVE_BOOL == type) {
        return phpc_session_append_str(buf, pos, cap, val->value[0] ? "b:1;" : "b:0;", 4);
    }
    if (PHPC_TYPE_NATIVE_LONG == type) {
        long long n;
        char tmp[48];
        int len;

        memcpy(&n, val->value, sizeof n);
        len = snprintf(tmp, sizeof tmp, "i:%lld;", (long long) n);
        if (len <= 0) {
            return 0;
        }

        return phpc_session_append_str(buf, pos, cap, tmp, (size_t) len);
    }
    if (PHPC_TYPE_STRING == type || (type & 0x7f) == PHPC_TYPE_STRING) {
        __string__ *str = *((__string__ **) val->value);

        return phpc_session_encode_quoted_string(buf, pos, cap, str);
    }

    return 0;
}

static void phpc_session_merge_hashtable(__hashtable__ *dest, __hashtable__ *src)
{
    __strkey_node__ *node;

    if (NULL == dest || NULL == src) {
        return;
    }
    for (node = src->strKeys; NULL != node; node = node->next) {
        int8_t type = node->value.type;

        if (PHPC_TYPE_NULL == type) {
            __hashtable__setStringKeyString(dest, node->key, cstr_to_string(""));
        } else if (PHPC_TYPE_NATIVE_BOOL == type) {
            __hashtable__setStringKeyBool(dest, node->key, node->value.value[0] ? 1 : 0);
        } else if (PHPC_TYPE_NATIVE_LONG == type) {
            long long n;

            memcpy(&n, node->value.value, sizeof n);
            __hashtable__setStringKeyLong(dest, node->key, n);
        } else if (PHPC_TYPE_STRING == type || (type & 0x7f) == PHPC_TYPE_STRING) {
            __string__ *str = *((__string__ **) node->value.value);

            __hashtable__setStringKeyString(dest, node->key, str);
        }
    }
}

static int phpc_session_encode_hashtable(char *buf, size_t *pos, size_t cap, __hashtable__ *ht)
{
    __strkey_node__ *node;
    unsigned long count = 0;
    __strkey_node__ **nodes;
    unsigned long i;
    unsigned long j;

    if (NULL == ht) {
        return phpc_session_append_str(buf, pos, cap, "a:0:{}", 6);
    }
    for (node = ht->strKeys; NULL != node; node = node->next) {
        count++;
    }
    if (0 == count) {
        return phpc_session_append_str(buf, pos, cap, "a:0:{}", 6);
    }
    nodes = ( __strkey_node__ **) malloc(count * sizeof(__strkey_node__ *));
    if (NULL == nodes) {
        return 0;
    }
    i = 0;
    for (node = ht->strKeys; NULL != node; node = node->next) {
        nodes[i++] = node;
    }
    for (i = 0; i + 1 < count; i++) {
        for (j = i + 1; j < count; j++) {
            if (strcmp(nf_strdata(nodes[i]->key), nf_strdata(nodes[j]->key)) > 0) {
                __strkey_node__ *tmp = nodes[i];
                nodes[i] = nodes[j];
                nodes[j] = tmp;
            }
        }
    }
    if (!phpc_session_append_str(buf, pos, cap, "a:", 2)) {
        free(nodes);
        return 0;
    }
    if (!phpc_session_append_decimal(buf, pos, cap, count)) {
        free(nodes);
        return 0;
    }
    if (!phpc_session_append_str(buf, pos, cap, ":{", 2)) {
        free(nodes);
        return 0;
    }
    for (i = 0; i < count; i++) {
        if (
            !phpc_session_encode_quoted_string(buf, pos, cap, nodes[i]->key)
            || !phpc_session_encode_value(buf, pos, cap, &nodes[i]->value)
        ) {
            free(nodes);
            return 0;
        }
    }
    free(nodes);

    return phpc_session_append_str(buf, pos, cap, "}", 1);
}

void phpc_session_load_from_disk(void)
{
    char path[512];
    FILE *fp;
    char *buf;
    long file_len;
    __hashtable__ *decoded;

    if (__phpc_session_id_len <= 0) {
        return;
    }
    if (!phpc_session_storage_path(__phpc_session_id_storage, path, sizeof path)) {
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
    file_len = ftell(fp);
    if (file_len <= 0 || file_len > (long) PHPC_SESSION_ENCODE_CAP) {
        fclose(fp);
        return;
    }
    if (0 != fseek(fp, 0, SEEK_SET)) {
        fclose(fp);
        return;
    }
    buf = (char *) malloc((size_t) file_len + 1);
    if (NULL == buf) {
        fclose(fp);
        return;
    }
    if ((long) fread(buf, 1, (size_t) file_len, fp) != file_len) {
        free(buf);
        fclose(fp);
        return;
    }
    fclose(fp);
    buf[file_len] = '\0';
    decoded = phpc_session_decode_payload(buf, (size_t) file_len);
    free(buf);
    if (NULL != decoded) {
        if (NULL == sg_SESSION) {
            sg_SESSION = __hashtable__alloc();
        }
        phpc_session_merge_hashtable(sg_SESSION, decoded);
    }
}

void phpc_session_save_to_disk(void)
{
    char path[512];
    char encode_buf[PHPC_SESSION_ENCODE_CAP];
    size_t pos = 0;
    FILE *fp;

    if (__phpc_session_id_len <= 0 || NULL == sg_SESSION) {
        return;
    }
    if (!phpc_session_encode_hashtable(encode_buf, &pos, sizeof encode_buf, sg_SESSION)) {
        return;
    }
    encode_buf[pos] = '\0';
    if (!phpc_session_storage_path(__phpc_session_id_storage, path, sizeof path)) {
        return;
    }
    mkdir(phpc_session_storage_dir(), 0700);
    fp = fopen(path, "wb");
    if (NULL == fp) {
        return;
    }
    fwrite(encode_buf, 1, pos, fp);
    fclose(fp);
}

void phpc_session_unlink_file(void)
{
    char path[512];

    if (__phpc_session_id_len <= 0) {
        return;
    }
    if (phpc_session_storage_path(__phpc_session_id_storage, path, sizeof path)) {
        remove(path);
    }
}

int phpc_session_apply_incoming_cookie(void)
{
    char id_buf[PHPC_SESSION_ID_MAX + 1];

    if (phpc_session_read_cookie_id(id_buf, sizeof id_buf)) {
        size_t len = strlen(id_buf);
        if (len > PHPC_SESSION_ID_MAX) {
            len = PHPC_SESSION_ID_MAX;
        }
        memcpy(__phpc_session_id_storage, id_buf, len);
        __phpc_session_id_storage[len] = '\0';
        __phpc_session_id_len = (int64_t) len;

        return 1;
    }

    return 0;
}
