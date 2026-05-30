/*
 * putenv()/getenv() local environment table (php-src EG(env), issue #3710).
 * Linked for AOT and MCJIT (lib/JIT/Builtin/StringEnvLocal.php).
 */

#include <stdlib.h>
#include <string.h>

typedef struct {
    char *name;
    char *value;
} phpc_env_local_entry;

static phpc_env_local_entry *phpc_env_local = NULL;
static size_t phpc_env_local_count = 0;

static void phpc_env_local_free_entry(phpc_env_local_entry *entry)
{
    if (NULL == entry) {
        return;
    }
    free(entry->name);
    free(entry->value);
    entry->name = NULL;
    entry->value = NULL;
}

static const char *phpc_env_local_lookup(const char *name)
{
    size_t i;

    for (i = 0; i < phpc_env_local_count; ++i) {
        if (0 == strcmp(phpc_env_local[i].name, name)) {
            return phpc_env_local[i].value;
        }
    }

    return NULL;
}

static void phpc_env_local_remove(const char *name)
{
    size_t i;

    for (i = 0; i < phpc_env_local_count; ++i) {
        if (0 != strcmp(phpc_env_local[i].name, name)) {
            continue;
        }
        phpc_env_local_free_entry(&phpc_env_local[i]);
        if (i + 1 < phpc_env_local_count) {
            phpc_env_local[i] = phpc_env_local[phpc_env_local_count - 1];
        }
        --phpc_env_local_count;
        if (phpc_env_local_count > 0) {
            phpc_env_local = (phpc_env_local_entry *) realloc(
                phpc_env_local,
                phpc_env_local_count * sizeof(phpc_env_local_entry)
            );
        } else {
            free(phpc_env_local);
            phpc_env_local = NULL;
        }

        return;
    }
}

static void phpc_env_local_set(const char *name, const char *value)
{
    size_t i;
    char *name_copy;
    char *value_copy;

    phpc_env_local_remove(name);
    name_copy = strdup(name);
    value_copy = strdup(value);
    if (NULL == name_copy || NULL == value_copy) {
        free(name_copy);
        free(value_copy);

        return;
    }
    phpc_env_local = (phpc_env_local_entry *) realloc(
        phpc_env_local,
        (phpc_env_local_count + 1) * sizeof(phpc_env_local_entry)
    );
    if (NULL == phpc_env_local) {
        free(name_copy);
        free(value_copy);

        return;
    }
    i = phpc_env_local_count++;
    phpc_env_local[i].name = name_copy;
    phpc_env_local[i].value = value_copy;
}

static int phpc_env_parse_putenv(const char *setting, char **name_out, char **value_out, int *unset_out)
{
    const char *eq;

    if (NULL == setting || NULL == name_out || NULL == value_out || NULL == unset_out) {
        return 0;
    }
    eq = strchr(setting, '=');
    if (NULL == eq) {
        *name_out = strdup(setting);
        *value_out = NULL;
        *unset_out = 1;

        return NULL != *name_out;
    }
    *name_out = (char *) malloc((size_t) (eq - setting) + 1);
    if (NULL == *name_out) {
        return 0;
    }
    memcpy(*name_out, setting, (size_t) (eq - setting));
    (*name_out)[eq - setting] = '\0';
    *value_out = strdup(eq + 1);
    *unset_out = 0;

    return NULL != *value_out;
}

const char *__compiler_env_local_lookup(const char *name)
{
    if (NULL == name) {
        return NULL;
    }

    return phpc_env_local_lookup(name);
}

void __compiler_env_register_putenv(const char *setting)
{
    char *name;
    char *value;
    int unset;

    if (NULL == setting || !phpc_env_parse_putenv(setting, &name, &value, &unset)) {
        return;
    }
    if ('\0' == name[0]) {
        free(name);
        free(value);

        return;
    }
    if (unset) {
        phpc_env_local_remove(name);
    } else {
        phpc_env_local_set(name, value);
    }
    free(name);
    free(value);
}
