/*
 * session_start() / session_write_close() for JIT/AOT (issues #1182–#1186, #1882).
 * Minimal libc for MCJIT bitcode (stdint only).
 */

#include <stdint.h>

typedef struct __hashtable__ __hashtable__;
typedef struct __string__ __string__;
typedef struct __value__ __value__;

#define PHPC_SESSION_ID_MAX 128
#define PHPC_SESSION_NAME_MAX 128

extern char __phpc_session_id_storage[PHPC_SESSION_ID_MAX + 1];
extern char __phpc_session_name_storage[PHPC_SESSION_NAME_MAX + 1];
extern int64_t __phpc_session_id_len;
extern int64_t __phpc_session_name_len;
extern char __phpc_session_active;

extern __hashtable__ *sg_SESSION;

extern __hashtable__ *__hashtable__alloc(void);
extern __string__ *__string__init(long long size, const char *value);
extern void __value__writeBool(__value__ *out, int value);
extern __string__ *__compiler_random_bytes(int64_t len);

typedef uint64_t phpc_size;

static phpc_size nf_strlen(__string__ *s)
{
    if (0 == s) {
        return 0;
    }

    return (phpc_size) *((long long *) ((char *) s + sizeof(void *)));
}

static const char *nf_strdata(__string__ *s)
{
    if (0 == s) {
        return "";
    }

    return (const char *) s + sizeof(void *) + sizeof(long long);
}

static void phpc_memcpy(char *dst, const char *src, phpc_size n)
{
    phpc_size i;

    for (i = 0; i < n; i++) {
        dst[i] = src[i];
    }
}

static void session_store_id(const char *id, phpc_size len)
{
    if (len > PHPC_SESSION_ID_MAX) {
        len = PHPC_SESSION_ID_MAX;
    }
    phpc_memcpy(__phpc_session_id_storage, id, len);
    __phpc_session_id_storage[len] = '\0';
    __phpc_session_id_len = (int64_t) len;
}

void __phpc_session_generate_new_id(void)
{
    static const char hex[] = "0123456789abcdef";
    __string__ *raw;
    const char *bytes;
    phpc_size raw_len;
    char out[PHPC_SESSION_ID_MAX + 1];
    phpc_size i;

    raw = __compiler_random_bytes(16);
    if (0 == raw) {
        session_store_id("", 0);

        return;
    }
    bytes = nf_strdata(raw);
    raw_len = nf_strlen(raw);
    if (raw_len < 16) {
        session_store_id("", 0);

        return;
    }
    for (i = 0; i < 32; i++) {
        unsigned char b = (unsigned char) bytes[i / 2];
        out[i] = hex[(i & 1) ? (b & 0x0f) : ((b >> 4) & 0x0f)];
    }
    out[32] = '\0';
    session_store_id(out, 32);
}

void __phpc_session_start_apply(__value__ *out)
{
    if (__phpc_session_active) {
        __value__writeBool(out, 0);

        return;
    }

    if (__phpc_session_name_len <= 0) {
        phpc_memcpy(__phpc_session_name_storage, "PHPSESSID", 9);
        __phpc_session_name_storage[9] = '\0';
        __phpc_session_name_len = 9;
    }

    if (__phpc_session_id_len <= 0) {
        __phpc_session_generate_new_id();
    }

    if (0 == sg_SESSION) {
        sg_SESSION = __hashtable__alloc();
    }

    __phpc_session_active = 1;
    __value__writeBool(out, 1);
}

void __phpc_session_write_close_apply(__value__ *out)
{
    if (!__phpc_session_active) {
        __value__writeBool(out, 0);

        return;
    }

    __phpc_session_active = 0;
    __value__writeBool(out, 1);
}

void __phpc_session_regenerate_id_apply(__value__ *out, int8_t delete_old)
{
    (void) delete_old;

    if (!__phpc_session_active) {
        __value__writeBool(out, 0);

        return;
    }

    __phpc_session_generate_new_id();
    __value__writeBool(out, 1);
}

void __phpc_session_destroy_apply(__value__ *out)
{
    if (!__phpc_session_active) {
        __value__writeBool(out, 0);

        return;
    }

    __phpc_session_active = 0;
    __phpc_session_id_storage[0] = '\0';
    __phpc_session_id_len = 0;
    sg_SESSION = __hashtable__alloc();
    __value__writeBool(out, 1);
}
