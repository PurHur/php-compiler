/*
 * Process helpers for AOT/JIT (inventory blocker batch 2, #2779 Linker floor).
 */

#include <stdint.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <unistd.h>
#include <sys/types.h>
#include <sys/wait.h>

typedef struct __string__ __string__;
typedef struct __ref__ {
    void *vtable;
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

extern __string__ *__string__init(long long size, const char *value);
extern __hashtable__ *__hashtable__alloc(void);
extern void __hashtable__setStringKeyString(__hashtable__ *ht, __string__ *key, __string__ *value);
extern void __hashtable__setStringKeyLong(__hashtable__ *ht, __string__ *key, long long value);

#define PHPC_TYPE_STRING 4

static size_t phpc_strlen(__string__ *s)
{
    if (NULL == s) {
        return 0;
    }

    return (size_t) *((long long *) ((char *) s + sizeof(void *)));
}

static const char *phpc_strdata(__string__ *s)
{
    if (NULL == s) {
        return "";
    }

    return (const char *) s + sizeof(void *) + sizeof(long long);
}

static __string__ *phpc_cstr_to_string(const char *cstr)
{
    return __string__init((long long) strlen(cstr), cstr);
}

static __string__ *phpc_read_stream_all(FILE *fp)
{
    char chunk[4096];
    char *buf;
    size_t len;
    size_t cap;
    char *grown;
    __string__ *result;

    cap = 4096;
    len = 0;
    buf = (char *) malloc(cap);
    if (NULL == buf) {
        return NULL;
    }
    while (NULL != fgets(chunk, (int) sizeof(chunk), fp)) {
        size_t chunk_len = strlen(chunk);
        if (len + chunk_len + 1 > cap) {
            cap = (len + chunk_len + 1) * 2;
            grown = (char *) realloc(buf, cap);
            if (NULL == grown) {
                free(buf);

                return NULL;
            }
            buf = grown;
        }
        memcpy(buf + len, chunk, chunk_len);
        len += chunk_len;
    }
    if (0 == len) {
        free(buf);

        return __string__init(0, "");
    }
    result = __string__init((long long) len, buf);
    free(buf);

    return result;
}

__string__ *__compiler_shell_exec(__string__ *cmd)
{
    const char *command;
    FILE *fp;
    __string__ *result;

    if (NULL == cmd) {
        return NULL;
    }
    command = phpc_strdata(cmd);
    if ('\0' == *command) {
        return NULL;
    }
    fp = popen(command, "r");
    if (NULL == fp) {
        return NULL;
    }
    result = phpc_read_stream_all(fp);
    if (-1 == pclose(fp) && (NULL == result || 0 == phpc_strlen(result))) {
        return NULL;
    }

    return result;
}

__string__ *__compiler_escapeshellarg(__string__ *arg)
{
    const char *src;
    size_t src_len;
    size_t out_cap;
    size_t out_len;
    char *out;
    char *grown;
    __string__ *result;

    if (NULL == arg) {
        return __string__init(0, "''");
    }
    src = phpc_strdata(arg);
    src_len = phpc_strlen(arg);
    out_cap = src_len * 4 + 3;
    out = (char *) malloc(out_cap);
    if (NULL == out) {
        return NULL;
    }
    out_len = 0;
    out[out_len++] = '\'';
    for (size_t i = 0; i < src_len; i++) {
        if ('\'' == src[i]) {
            if (out_len + 4 >= out_cap) {
                out_cap = (out_len + 4) * 2;
                grown = (char *) realloc(out, out_cap);
                if (NULL == grown) {
                    free(out);

                    return NULL;
                }
                out = grown;
            }
            out[out_len++] = '\'';
            out[out_len++] = '\\';
            out[out_len++] = '\'';
            out[out_len++] = '\'';
        } else {
            if (out_len + 1 >= out_cap) {
                out_cap = (out_len + 1) * 2;
                grown = (char *) realloc(out, out_cap);
                if (NULL == grown) {
                    free(out);

                    return NULL;
                }
                out = grown;
            }
            out[out_len++] = src[i];
        }
    }
    if (out_len + 1 >= out_cap) {
        out_cap = out_len + 2;
        grown = (char *) realloc(out, out_cap);
        if (NULL == grown) {
            free(out);

            return NULL;
        }
        out = grown;
    }
    out[out_len++] = '\'';
    result = __string__init((long long) out_len, out);
    free(out);

    return result;
}

/* php_escape_shell_cmd — ext/standard/exec.c (Unix subset, #3417). */
__string__ *__compiler_escapeshellcmd(__string__ *cmd_in)
{
    const char *str;
    size_t l;
    size_t x;
    size_t y;
    char *out;
    size_t out_cap;
    char *grown;
    const char *p = NULL;
    __string__ *result;

    if (NULL == cmd_in) {
        return __string__init(0, "");
    }
    str = phpc_strdata(cmd_in);
    l = phpc_strlen(cmd_in);
    if (0 == l) {
        return __string__init(0, "");
    }

    out_cap = (2 * l) + 1;
    out = (char *) malloc(out_cap);
    if (NULL == out) {
        return NULL;
    }

    for (x = 0, y = 0; x < l; x++) {
        switch (str[x]) {
        case '"':
        case '\'':
            if (!p && (p = memchr(str + x + 1, str[x], l - x - 1))) {
                /* paired quote ahead — leave unescaped */
            } else if (p && *p == str[x]) {
                p = NULL;
            } else {
                if (y + 2 >= out_cap) {
                    out_cap = (y + 2) * 2;
                    grown = (char *) realloc(out, out_cap);
                    if (NULL == grown) {
                        free(out);

                        return NULL;
                    }
                    out = grown;
                }
                out[y++] = '\\';
            }
            if (y + 1 >= out_cap) {
                out_cap = (y + 1) * 2;
                grown = (char *) realloc(out, out_cap);
                if (NULL == grown) {
                    free(out);

                    return NULL;
                }
                out = grown;
            }
            out[y++] = str[x];
            break;
        case '#':
        case '&':
        case ';':
        case '`':
        case '|':
        case '*':
        case '?':
        case '~':
        case '<':
        case '>':
        case '^':
        case '(':
        case ')':
        case '[':
        case ']':
        case '{':
        case '}':
        case '$':
        case '\\':
        case '\n':
        case (char) 0xff:
            if (y + 2 >= out_cap) {
                out_cap = (y + 2) * 2;
                grown = (char *) realloc(out, out_cap);
                if (NULL == grown) {
                    free(out);

                    return NULL;
                }
                out = grown;
            }
            out[y++] = '\\';
            /* fall through */
        default:
            if (y + 1 >= out_cap) {
                out_cap = (y + 1) * 2;
                grown = (char *) realloc(out, out_cap);
                if (NULL == grown) {
                    free(out);

                    return NULL;
                }
                out = grown;
            }
            out[y++] = str[x];
            break;
        }
    }

    result = __string__init((long long) y, out);
    free(out);

    return result;
}

static void phpc_apply_env_hashtable(__hashtable__ *env)
{
    __strkey_node__ *node;

    if (NULL == env) {
        return;
    }
    for (node = env->strKeys; NULL != node; node = node->next) {
        __string__ *val;

        if ((node->value.type & 0x7f) != PHPC_TYPE_STRING) {
            continue;
        }
        val = *((__string__ **) node->value.value);
        if (NULL == node->key || NULL == val) {
            continue;
        }
        setenv(phpc_strdata(node->key), phpc_strdata(val), 1);
    }
}

__hashtable__ *__compiler_phpc_run_command(__string__ *cmd, __hashtable__ *env)
{
    const char *command;
    int stdout_pipe[2];
    int stderr_pipe[2];
    pid_t pid;
    __hashtable__ *result;
    __string__ *stdout_str;
    __string__ *stderr_str;
    int status;
    int exit_code;

    if (NULL == cmd) {
        return NULL;
    }
    command = phpc_strdata(cmd);
    if ('\0' == *command) {
        return NULL;
    }
    if (0 != pipe(stdout_pipe) || 0 != pipe(stderr_pipe)) {
        return NULL;
    }
    pid = fork();
    if (-1 == pid) {
        close(stdout_pipe[0]);
        close(stdout_pipe[1]);
        close(stderr_pipe[0]);
        close(stderr_pipe[1]);

        return NULL;
    }
    if (0 == pid) {
        close(stdout_pipe[0]);
        close(stderr_pipe[0]);
        dup2(stdout_pipe[1], STDOUT_FILENO);
        dup2(stderr_pipe[1], STDERR_FILENO);
        close(stdout_pipe[1]);
        close(stderr_pipe[1]);
        phpc_apply_env_hashtable(env);
        execl("/bin/sh", "sh", "-c", command, (char *) NULL);
        _exit(127);
    }
    close(stdout_pipe[1]);
    close(stderr_pipe[1]);
    {
        FILE *stdout_fp = fdopen(stdout_pipe[0], "r");
        FILE *stderr_fp = fdopen(stderr_pipe[0], "r");

        stdout_str = stdout_fp ? phpc_read_stream_all(stdout_fp) : NULL;
        stderr_str = stderr_fp ? phpc_read_stream_all(stderr_fp) : NULL;
        if (NULL != stdout_fp) {
            fclose(stdout_fp);
        } else {
            close(stdout_pipe[0]);
        }
        if (NULL != stderr_fp) {
            fclose(stderr_fp);
        } else {
            close(stderr_pipe[0]);
        }
    }
    if (NULL == stdout_str) {
        stdout_str = __string__init(0, "");
    }
    if (NULL == stderr_str) {
        stderr_str = __string__init(0, "");
    }
    if (-1 == waitpid(pid, &status, 0)) {
        exit_code = 127;
    } else if (WIFEXITED(status)) {
        exit_code = WEXITSTATUS(status);
    } else {
        exit_code = 127;
    }
    result = __hashtable__alloc();
    if (NULL == result) {
        return NULL;
    }
    __hashtable__setStringKeyLong(result, phpc_cstr_to_string("code"), (long long) exit_code);
    __hashtable__setStringKeyString(result, phpc_cstr_to_string("stdout"), stdout_str);
    __hashtable__setStringKeyString(result, phpc_cstr_to_string("stderr"), stderr_str);

    return result;
}
