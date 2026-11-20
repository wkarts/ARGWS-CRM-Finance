<?php

declare(strict_types=1);

namespace Multiempresa\Application;

use Multiempresa\Infrastructure\{Database,Vault};
use Multiempresa\Domain\Failure;
use Multiempresa\Contracts\{StorageDriver,EventAuthenticator,EventProcessor};
use Multiempresa\Infrastructure\{MySqlStorage,PrefixStorage};
use Multiempresa\Integration\{HmacAuthenticator,DurableInbox};

final class App
{
    public readonly Database $db;
    public readonly Vault $vault;
    public readonly Catalog $catalog;
    public readonly Auth $auth;
    public readonly Audit $audit;
    public readonly RateLimiter $limits;
    private array $drivers = [];
    private array $authenticators = [];
    private array $processors = [];
    public function __construct(public readonly array $config)
    {
        $this->db = new Database($config['central']);
        $this->vault = new Vault($config['master_key']);
        $this->audit = new Audit($this->db, $this->vault, $this);
        $this->limits = new RateLimiter($this->db);
        $this->catalog = new Catalog($this);
        $this->auth = new Auth($this);
        $this->registerStorage(new MySqlStorage());
        $this->registerStorage(new PrefixStorage());
        $this->registerAuthenticator('hmac', new HmacAuthenticator());
        $this->registerProcessor('durable_inbox', new DurableInbox($this));
        if (!empty($config['extensions_file'])) {
            $register = require $config['extensions_file'];
            if (!is_callable($register)) {
                throw new Failure('extensions', 'Registro de extensões inválido.', 503);
            }
            $register($this);
        }
    }
    public static function environment(): self
    {
        $path = \Multiempresa\Perfex\Installer::configPath();
        $config = require $path;
        if (!is_array($config)) {
            throw new Failure('configuration', 'Configuração inválida.', 503);
        }
        return new self($config);
    }
    public function registerStorage(StorageDriver $d): void
    {
        $this->drivers[$d->name()] = $d;
    }
    public function storage(string $name): StorageDriver
    {
        return $this->drivers[$name] ?? throw new Failure('storage_driver', 'Driver de armazenamento não registrado.');
    }
    public function registerAuthenticator(string $name, EventAuthenticator $a): void
    {
        $this->authenticators[$name] = $a;
    }
    public function authenticator(string $name): EventAuthenticator
    {
        return $this->authenticators[$name] ?? throw new Failure('provider', 'Provedor não registrado.');
    }
    public function registerProcessor(string $name, EventProcessor $p): void
    {
        $this->processors[$name] = $p;
    }
    public function processor(string $name): EventProcessor
    {
        return $this->processors[$name] ?? throw new Failure('processor', 'Processador não registrado.');
    }
    public function state(string $path = ''): string
    {
        return rtrim($this->config['state_dir'], '/') . '/' . ltrim($path, '/');
    }
    public function storageNames(): array
    {
        return array_keys($this->drivers);
    }
    public function authenticatorNames(): array
    {
        return array_keys($this->authenticators);
    }
    public function processorNames(): array
    {
        return array_keys($this->processors);
    }
}
