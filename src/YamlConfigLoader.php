<?php

declare(strict_types=1);

namespace Ambit;

use Ambit\Exception\UnknownSetException;
use Ambit\Validation\SchemaValidator;
use InvalidArgumentException;
use Symfony\Component\Yaml\Yaml;

final class YamlConfigLoader
{
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
        foreach ($data as $name => $definition) {
            $inherited = $definition['extends'] ?? [];
            $parents[$name] = is_string($inherited) ? [$inherited] : $inherited;
            $configs[$name] = new Config(
                $this->resolvePaths($definition['paths'] ?? [], $directory),
                $this->resolvePaths($definition['exclude'] ?? [], $directory)
            );
        }

        $resolved = [];
        foreach (array_keys($configs) as $name) {
            $this->resolveSet($name, $configs, $parents, $resolved);
        }
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
        foreach ($parents[$name] as $parent) {
            $inherited = $this->resolveSet($parent, $configs, $parents, $resolved);
            $paths = array_merge($paths, $inherited->paths());
            $exclusions = array_merge($exclusions, $inherited->exclusions());
        }
        return $resolved[$name] = new Config(
            array_merge($paths, $configs[$name]->paths()),
            array_merge($exclusions, $configs[$name]->exclusions())
        );
    }

    /**
     * @param string[] $paths
     * @return string[]
     */
    private function resolvePaths(array $paths, string $directory): array
    {
        $resolved = [];
        foreach ($paths as $path) {
            $path = str_replace('\\', '/', $path);
            $resolved[] = preg_match('~^(?:/|[a-zA-Z]:/)~', $path)
                ? $path
                : rtrim($directory, '/') . '/' . $path;
        }
        return $resolved;
    }
}
