# Ambit

Define which files your PHP tools should process in one place, and select files
from those scopes or Git changes.

Compatible with PHP 7.4+.

## Configuration and usage

Create an `ambit.yaml` in your project root. A single `php` set is enough;
additional sets are optional:

```yaml
php:
    paths:
        - src
        - tests
    exclude:
        - '*/generated/*'
```

```php
use Ambit\Scope;

$php = Scope::fromProject(__DIR__); // Selects the php set by default.
```

If tools need different scopes, add dedicated sets to the same file. They can inherit
paths and exclusions from `php`:

```yaml
rector:
    extends: php
    exclude:
        - tests/Fixtures

lint:
    extends: php
    paths:
        - bin
        - config
    exclude:
        - config/legacy.php
```

```php
use Ambit\Scope;

$rector = Scope::fromProject(__DIR__, 'rector');
$lint = Scope::fromProject(__DIR__, 'lint');
```

The `lint` set includes `src`, `tests`, `bin`, and `config`, and excludes both
`*/generated/*` and `config/legacy.php`.

- `extends` accepts a set name or a list, such as `extends: [php, shared]`.
  Parents may themselves extend other sets and may be declared in any order.
- Paths and exclusions are additive: parent entries come first, in parent
  declaration order, followed by the child's entries. Duplicates are removed,
  keeping their first occurrence.
- Named sets may omit `paths` and `exclude`; both default to empty lists. Empty
  lists keep inherited entries. Inherited paths and exclusions cannot be removed.
- All relative paths and patterns are anchored to the directory containing
  `ambit.yaml`. Absolute paths remain absolute. Loading a `Config` does not check
  whether paths exist; creating a `Scope` checks literal inclusion paths in the
  selected set.
- Omitting the set name selects `php` by default. If it is missing, loading fails just as
  it does for any other unknown set name.
- Unknown keys, unknown set names, malformed definitions, and inheritance cycles
  are rejected. All set definitions are validated, including unused sets.

For direct configuration loading, use
`(new Ambit\YamlConfigLoader())->load($file, 'lint')`, which returns an immutable
`Ambit\Config` with absolute paths and exclusion patterns.
Use `loadAll($file)` to obtain every named set with inheritance resolved.

### Using Ambit with Rector

In `rector.php`, use the `rector` set for paths and exclusions alongside your rule
configuration:

```php
use Ambit\Scope;
use Rector\Config\RectorConfig;

$scope = Scope::fromProject(__DIR__, 'rector');

return RectorConfig::configure()
    ->withPaths($scope->paths())
    ->withSkip($scope->exclusions());
```

### Path matching

Inclusion patterns containing `*` use PHP's `glob()`; `**` is not a recursive
globstar. Unmatched inclusion patterns select no files. Exclusions follow
Rector-style prefix/suffix and `fnmatch()` matching. Use a trailing `/` on literal
directory exclusions to avoid matching sibling directories with the same prefix.

## Validation

Ambit provides two types of validation:

- **Schema** validation checks the parsed configuration's set definitions, keys,
  path lists, parent references, and inheritance cycles. It reports the first error
  without reading files or requiring configured paths to exist.
- **Filesystem** validation checks whether inclusions and exclusions match existing
  files or directories. Each unmatched entry produces a warning, including missing
  literal inclusions.

`YamlConfigLoader` parses YAML, validates its schema, and resolves paths and
inheritance into `Config` objects. Invalid YAML raises
`Symfony\Component\Yaml\Exception\ParseException`; schema violations raise
`InvalidArgumentException`. Loading an unknown set raises
`Ambit\Exception\UnknownSetException`, an `InvalidArgumentException`.

### CLI

After installing Ambit as a dependency, run:

```bash
vendor/bin/ambit validate
vendor/bin/ambit validate path/to/ambit.yaml --set=rector
vendor/bin/ambit validate --schema-only
vendor/bin/ambit validate --paths-only
vendor/bin/ambit validate --exclusions-only
```

The configuration file defaults to `ambit.yaml` in the current working directory.
By default, filesystem checks cover every set; `--set=NAME` limits filesystem checks
to that set. Schema checks always cover every set. Only one of `--schema-only`,
`--paths-only`, and `--exclusions-only` may be supplied.

Example output:

```text
Validating "ambit.yaml".
WARNING [filesystem] php.paths "/project/modules/*/src": This does not match any real path.
WARNING [filesystem] php.exclude "/project/cache/": This does not match any real path.
0 error(s), 2 warning(s).
```

Exit status is `0` when there are no errors or warnings, `1` when there are errors
(including runs that also have warnings), `2` for incorrect CLI arguments, and `3`
for warnings only.

### PHP API

`SchemaValidator` takes already parsed configuration data:

```php
use Ambit\Validation\SchemaValidator;

$data = ['php' => ['paths' => ['src'], 'exclude' => ['cache/']]];
$result = (new SchemaValidator())->validate($data);

// Or throw InvalidArgumentException on a schema violation:
(new SchemaValidator())->assertValid($data);
```

`FilesystemValidator` checks an existing or programmatically created `Config`,
independently of YAML loading:

```php
use Ambit\Validation\FilesystemValidator;
use Ambit\YamlConfigLoader;

$config = (new YamlConfigLoader())->load(__DIR__ . '/ambit.yaml');
$result = (new FilesystemValidator())->validate($config, __DIR__);
```

Both validators return a result with issues, errors, and warnings:

```php
foreach ($result->issues() as $issue) {
    echo $issue->severity() . ': ' . $issue->path() . ' ' . $issue->message() . PHP_EOL;
}

$result->isValid(); // True when there are no errors; warnings are allowed.
$result->errors();
$result->warnings();
```

Inclusions use the same `glob()` rules as `Scope`. Exclusions use `PathMatcher`
against the project directory tree, including hidden files and empty directories,
and resolved inclusion paths outside that tree. Exclusions with external absolute
prefixes are also checked at those locations. They need not overlap inclusions to
count as matching. Directory symlinks are not traversed recursively, preventing
cycles. An unreadable tree produces a filesystem error rather than potentially false
unmatched-exclusion warnings. Large trees or broad external patterns can make
filesystem validation slower.
