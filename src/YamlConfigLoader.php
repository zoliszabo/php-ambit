<?php

declare(strict_types=1);

namespace Ambit;

use InvalidArgumentException;
use Symfony\Component\Yaml\Yaml;

final class YamlConfigLoader
{
    public function load(string $file, string $set = 'php'): Config
    {
        $config = Yaml::parseFile($file);
        if (!is_array($config)) {
            throw new InvalidArgumentException('Scope configuration must be a mapping of named sets: ' . $file);
        }

        $directory = realpath(dirname($file));
        if ($directory === false) {
            throw new InvalidArgumentException('Configuration directory does not exist: ' . dirname($file));
        }
        $directory = str_replace('\\', '/', $directory);

        $configs = [];
        $parents = [];
        foreach ($config as $name => $definition) {
            if (!is_string($name) || $name === '' || !is_array($definition)) {
                throw new InvalidArgumentException('Scope configuration must map non-empty string names to set definitions.');
            }
            $this->assertKnownKeys($definition, ['paths', 'exclude', 'extends']);
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
            $configs[$name] = $this->createConfig($definition, $directory);
        }

        if (!isset($configs[$set])) {
            throw new InvalidArgumentException('Unknown scope set "' . $set . '".');
        }

        $resolved = [];
        foreach (array_keys($configs) as $name) {
            $this->resolveSet($name, $configs, $parents, [], $resolved);
        }
        return $resolved[$set];
    }

    /**
     * @param array<string, Config> $configs
     * @param array<string, string[]> $parents
     * @param string[] $chain
     * @param array<string, Config> $resolved
     */
    private function resolveSet(string $name, array $configs, array $parents, array $chain, array &$resolved): Config
    {
        if (isset($resolved[$name])) {
            return $resolved[$name];
        }
        if (in_array($name, $chain, true)) {
            throw new InvalidArgumentException('Circular scope set inheritance: ' . implode(' -> ', array_merge($chain, [$name])));
        }
        if (!isset($configs[$name])) {
            throw new InvalidArgumentException('Unknown parent scope set "' . $name . '" inherited by "' . end($chain) . '".');
        }
        $chain[] = $name;
        $paths = [];
        $exclusions = [];
        foreach ($parents[$name] as $parent) {
            $inherited = $this->resolveSet($parent, $configs, $parents, $chain, $resolved);
            $paths = array_merge($paths, $inherited->paths());
            $exclusions = array_merge($exclusions, $inherited->exclusions());
        }
        return $resolved[$name] = new Config(
            array_merge($paths, $configs[$name]->paths()),
            array_merge($exclusions, $configs[$name]->exclusions())
        );
    }

    /** @param string[] $allowed */
    private function assertKnownKeys(array $config, array $allowed): void
    {
        $unknown = array_diff(array_keys($config), $allowed);
        if ($unknown !== []) {
            throw new InvalidArgumentException('Unknown scope configuration keys: ' . implode(', ', $unknown));
        }
    }

    private function createConfig(array $config, string $directory): Config
    {
        $config += ['paths' => [], 'exclude' => []];
        foreach (['paths', 'exclude'] as $key) {
            if (!is_array($config[$key])) {
                throw new InvalidArgumentException('Scope "' . $key . '" must be a list of paths.');
            }
        }
        return new Config(
            $this->resolvePaths($config['paths'], $directory),
            $this->resolvePaths($config['exclude'], $directory)
        );
    }

    /**
     * @param string[] $paths
     * @return string[]
     */
    private function resolvePaths(array $paths, string $directory): array
    {
        $resolved = [];
        foreach ($paths as $key => $path) {
            if (!is_string($path) || $path === '') {
                throw new InvalidArgumentException('Configuration paths must be non-empty strings.');
            }
            $path = str_replace('\\', '/', $path);
            $resolved[$key] = preg_match('~^(?:/|[a-zA-Z]:/)~', $path)
                ? $path
                : rtrim($directory, '/') . '/' . $path;
        }
        return $resolved;
    }
}
