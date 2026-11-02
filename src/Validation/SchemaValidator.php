<?php

declare(strict_types=1);

namespace Ambit\Validation;

use InvalidArgumentException;

final class SchemaValidator
{
    /** @param mixed $data The already parsed configuration, including non-array values. */
    public function validate($data): Result
    {
        try {
            $this->assertValid($data);
        } catch (InvalidArgumentException $exception) {
            return new Result([new Issue('error', 'schema', $exception->getMessage())]);
        }
        return new Result();
    }

    /**
     * @param mixed $data
     * @throws InvalidArgumentException When the parsed value violates the schema.
     */
    public function assertValid($data): void
    {
        if (!is_array($data)) {
            throw new InvalidArgumentException('Scope configuration must be a mapping of named sets.');
        }

        $parents = [];
        foreach ($data as $name => $definition) {
            if (!is_string($name) || $name === '' || !is_array($definition)) {
                throw new InvalidArgumentException('Scope configuration must map non-empty string names to set definitions.');
            }
            $unknown = array_diff(array_keys($definition), ['paths', 'exclude', 'extends']);
            if ($unknown !== []) {
                throw new InvalidArgumentException('Unknown scope configuration keys: ' . implode(', ', $unknown));
            }
            foreach (['paths', 'exclude'] as $key) {
                if (array_key_exists($key, $definition)) {
                    $this->assertPathList($definition[$key], $key);
                }
            }

            $inherited = array_key_exists('extends', $definition) ? $definition['extends'] : [];
            if (is_string($inherited)) {
                $inherited = [$inherited];
            }
            if (!is_array($inherited) || array_values($inherited) !== $inherited) {
                throw new InvalidArgumentException('Scope set "' . $name . '" extends must name a set or be a list of names.');
            }
            foreach ($inherited as $parent) {
                if (!is_string($parent) || $parent === '') {
                    throw new InvalidArgumentException('Scope set "' . $name . '" extends must contain non-empty set names.');
                }
            }
            $parents[$name] = $inherited;
        }

        if ($data === []) {
            throw new InvalidArgumentException('Scope configuration must contain at least one named set.');
        }

        $checked = [];
        foreach (array_keys($parents) as $name) {
            $this->assertInheritance($name, $parents, [], $checked);
        }
    }

    /** @param mixed $paths */
    private function assertPathList($paths, string $key): void
    {
        if (!is_array($paths) || array_values($paths) !== $paths) {
            throw new InvalidArgumentException('Scope "' . $key . '" must be a list of paths.');
        }
        foreach ($paths as $path) {
            if (!is_string($path) || $path === '') {
                throw new InvalidArgumentException('Configuration paths must be non-empty strings.');
            }
        }
    }

    /**
     * @param array<string, string[]> $parents
     * @param string[] $chain
     * @param array<string, bool> $checked
     */
    private function assertInheritance(string $name, array $parents, array $chain, array &$checked): void
    {
        if (isset($checked[$name])) {
            return;
        }
        if (in_array($name, $chain, true)) {
            throw new InvalidArgumentException('Circular scope set inheritance: ' . implode(' -> ', array_merge($chain, [$name])));
        }
        if (!isset($parents[$name])) {
            throw new InvalidArgumentException('Unknown parent scope set "' . $name . '" inherited by "' . end($chain) . '".');
        }
        $chain[] = $name;
        foreach ($parents[$name] as $parent) {
            $this->assertInheritance($parent, $parents, $chain, $checked);
        }
        $checked[$name] = true;
    }
}
