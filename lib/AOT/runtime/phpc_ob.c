/*
 * JIT/AOT output buffering for ob_start / ob_get_clean / ob_end_flush (issue #118, #1056).
 */

#include <stdio.h>
#include <string.h>

#define PHPC_OB_MAX_DEPTH 8
#define PHPC_OB_BUF_SIZE 65536

extern int __phpc_ob_level;
extern char __phpc_ob_storage[PHPC_OB_MAX_DEPTH][PHPC_OB_BUF_SIZE];
extern unsigned long __phpc_ob_len[PHPC_OB_MAX_DEPTH];

struct __value__;
struct __string__;

void __value__writeBool(struct __value__ *out, int value);
void __value__writeString(struct __value__ *out, struct __string__ *str);
struct __string__ *__string__init(long long len, const char *value);

int __phpc_sapi_output_started = 0;

static int ob_active_index(void)
{
    return __phpc_ob_level > 0 ? __phpc_ob_level - 1 : -1;
}

static void ob_append_bytes(const char *data, size_t len)
{
    int idx = ob_active_index();
    if (idx < 0) {
        if (data && len > 0) {
            __phpc_sapi_output_started = 1;
            fwrite(data, 1, len, stdout);
        }
        return;
    }
    unsigned long cap = PHPC_OB_BUF_SIZE - 1;
    unsigned long pos = __phpc_ob_len[idx];
    if (pos >= cap) {
        return;
    }
    if (len > cap - pos) {
        len = (size_t) (cap - pos);
    }
    memcpy(__phpc_ob_storage[idx] + pos, data, len);
    pos += (unsigned long) len;
    __phpc_ob_len[idx] = pos;
    __phpc_ob_storage[idx][pos] = '\0';
}

void __phpc_ob_start(void)
{
    if (__phpc_ob_level >= PHPC_OB_MAX_DEPTH) {
        return;
    }
    __phpc_ob_len[__phpc_ob_level] = 0;
    __phpc_ob_storage[__phpc_ob_level][0] = '\0';
    __phpc_ob_level++;
}

int __phpc_ob_get_level(void)
{
    return __phpc_ob_level;
}

void __phpc_ob_echo_cstr(const char *s)
{
    if (!s) {
        return;
    }
    ob_append_bytes(s, strlen(s));
}

void __phpc_ob_echo_char(char c)
{
    ob_append_bytes(&c, 1);
}

void __phpc_ob_echo_ll(long long v)
{
    char buf[32];
    int n = snprintf(buf, sizeof buf, "%lld", v);
    if (n > 0) {
        ob_append_bytes(buf, (size_t) n);
    }
}

void __phpc_ob_echo_double(double v)
{
    char buf[64];
    int n = snprintf(buf, sizeof buf, "%G", v);
    if (n > 0) {
        ob_append_bytes(buf, (size_t) n);
    }
}

void __phpc_ob_echo_substr(const char *s, unsigned long len)
{
    if (!s) {
        return;
    }
    ob_append_bytes(s, (size_t) len);
}

int __phpc_ob_get_clean(struct __value__ *out)
{
    if (!out || __phpc_ob_level <= 0) {
        if (out) {
            __value__writeBool(out, 0);
        }
        return 0;
    }
    __phpc_ob_level--;
    int idx = __phpc_ob_level;
    unsigned long len = __phpc_ob_len[idx];
    __value__writeString(out, __string__init((long long) len, __phpc_ob_storage[idx]));
    __phpc_ob_len[idx] = 0;
    __phpc_ob_storage[idx][0] = '\0';
    return 1;
}

int __phpc_ob_end_flush(struct __value__ *out)
{
    if (!out || __phpc_ob_level <= 0) {
        if (out) {
            __value__writeBool(out, 0);
        }
        return 0;
    }
    __phpc_ob_level--;
    int idx = __phpc_ob_level;
    unsigned long len = __phpc_ob_len[idx];
    if (len > 0) {
        ob_append_bytes(__phpc_ob_storage[idx], (size_t) len);
    }
    __phpc_ob_len[idx] = 0;
    __phpc_ob_storage[idx][0] = '\0';
    __value__writeBool(out, 1);
    return 1;
}

volatile int __phpc_shutdown_registered = 0;

void __phpc_shutdown_mark_registered(void)
{
    __phpc_shutdown_registered = 1;
}
