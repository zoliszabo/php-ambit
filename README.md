# Ambit

Shared project file scopes and Git change selection for PHP 7.4+.

## Configuration

Define named sets at the top level of your project's `ambit.yaml`:

```yaml
php:
    paths:
        - src
        - tests
    exclude:
        - '*/generated/*'

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

$php = Scope::fromProject(__DIR__); // Selects the php set.
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
- Omitting the set name selects `php`. If it is missing, loading fails just as
  it does for any other unknown set name. There is no first-set fallback.
- Unknown keys, unknown set names, malformed definitions, and inheritance cycles
  are rejected. All set definitions are validated, including unused sets.

For direct configuration loading, use
`(new Ambit\YamlConfigLoader())->load($file, 'lint')`, which returns an immutable
`Ambit\Config` with absolute paths and exclusion patterns.

### Path matching

Inclusion patterns containing `*` use PHP's `glob()`; `**` is not a recursive
globstar. Unmatched inclusion patterns select no files. Exclusions follow
Rector-style prefix/suffix and `fnmatch()` matching. Use a trailing `/` on literal
directory exclusions to avoid matching sibling directories with the same prefix.
