<?php

declare(strict_types=1);

namespace Ambit;

use Ambit\Exception\UnknownSetException;
use Ambit\Validation\SchemaValidator;
use InvalidArgumentException;
use Symfony\Component\Yaml\Yaml;

final class YamlConfigLoader
{
    /** @var array<string, array<string, array<string, string>>> */
    private array $originalEntries = [];

    /** @var string[] */
    private array $leafSets = [];

    /**
     * Sets that no other set extends, from the most recently loaded file.
     * @return string[]
     */
    public function leafSets(): array
    {
        return $this->leafSets;
    }

    /** Original entry from the most recently loaded file, including inheritance. */
    public function originalEntry(string $set, string $key, string $path): ?string
    {
        return $this->originalEntries[$set][$key][$path] ?? null;
    }

    public function load(string $file, string $set = 'php'): Config
    {
        $configs = $this->loadAll($file);
        if (!isset($configs[$set])) {
            throw new UnknownSetException($set);
        }
        return $configs[$set];
    }

    /** @return array<string, Config> All sets, with inheritance resolved. */
    public function loadAll(string $file): array
    {
        $this->originalEntries = [];
        $this->leafSets = [];
        // Parsing must succeed before the schema validator receives any data.
        $data = Yaml::parseFile($file);
        (new SchemaValidator())->assertValid($data);

        $directory = realpath(dirname($file));
        if ($directory === false) {
            throw new InvalidArgumentException('Configuration directory does not exist: ' . dirname($file));
        }
        $directory = str_replace('\\', '/', $directory);

        $configs = [];
        $parents = [];
        $extended = [];
        foreach ($data as $name => $definition) {
            $inherited = $definition['extends'] ?? [];
            $parents[$name] = is_string($inherited) ? [$inherited] : $inherited;
            foreach ($parents[$name] as $parent) {
                $extended[$parent] = true;
            }
            $paths = $this->resolveEntries($definition['paths'] ?? [], $directory);
            $exclusions = $this->resolveEntries($definition['exclude'] ?? [], $directory);
            $this->originalEntries[$name] = ['paths' => $paths, 'exclude' => $exclusions];
            $configs[$name] = new Config(
                array_keys($paths),
                array_keys($exclusions)
            );
        }

        $resolved = [];
        foreach (array_keys($configs) as $name) {
            $this->resolveSet($name, $configs, $parents, $resolved);
        }
        $this->leafSets = array_values(array_diff(array_keys($configs), array_keys($extended)));
        return $resolved;
    }

    /**
     * @param array<string, Config> $configs
     * @param array<string, string[]> $parents
     * @param array<string, Config> $resolved
     */
    private function resolveSet(string $name, array $configs, array $parents, array &$resolved): Config
    {
        if (isset($resolved[$name])) {
            return $resolved[$name];
        }
        $paths = [];
        $exclusions = [];
        $originals = ['paths' => [], 'exclude' => []];
        foreach ($parents[$name] as $parent) {
            $inherited = $this->resolveSet($parent, $configs, $parents, $resolved);
            $paths = array_merge($paths, $inherited->paths());
            $exclusions = array_merge($exclusions, $inherited->exclusions());
            $originals['paths'] += $this->originalEntries[$parent]['paths'];
            $originals['exclude'] += $this->originalEntries[$parent]['exclude'];
        }
        $originals['paths'] += $this->originalEntries[$name]['paths'];
        $originals['exclude'] += $this->originalEntries[$name]['exclude'];
        $this->originalEntries[$name] = $originals;
        return $resolved[$name] = new Config(
            array_merge($paths, $configs[$name]->paths()),
            array_merge($exclusions, $configs[$name]->exclusions())
        );
    }

    /**
     * @param string[] $paths
     * @return array<string, string> Resolved paths mapped to their first original entry.
     */
    private function resolveEntries(array $paths, string $directory): array
    {
        $resolved = [];
        foreach ($paths as $path) {
            $original = $path;
            $path = str_replace('\\', '/', $path);
            $absolute = preg_match('~^(?:/|[a-zA-Z]:/)~', $path)
                ? $path
                : rtrim($directory, '/') . '/' . $path;
            if (!isset($resolved[$absolute])) {
                $resolved[$absolute] = $original;
            }
        }
        return $resolved;
    }
}
