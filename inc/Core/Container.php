<?php

declare(strict_types=1);

namespace Sitevero\Core;

/**
 * Lightweight service container for Sitevero.
 */
final class Container
{
    private static ?self $instance = null;

    /**
     * @var array<string, mixed>
     */
    private array $services = [];

    /**
     * @var array<string, callable>
     */
    private array $factories = [];

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Set a shared service instance.
     */
    public function set(string $id, mixed $service): void
    {
        $this->services[$id] = $service;
    }

    /**
     * Register a factory callback for lazy service creation.
     */
    public function bind(string $id, callable $factory): void
    {
        $this->factories[$id] = $factory;
    }

    /**
     * Retrieve a service instance.
     */
    public function get(string $id): mixed
    {
        if (isset($this->services[$id])) {
            return $this->services[$id];
        }

        if (isset($this->factories[$id])) {
            $this->services[$id] = ($this->factories[$id])($this);
            return $this->services[$id];
        }

        return null;
    }

    /**
     * Check if service or factory is registered.
     */
    public function has(string $id): bool
    {
        return isset($this->services[$id]) || isset($this->factories[$id]);
    }
}
