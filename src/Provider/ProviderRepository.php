<?php
declare(strict_types=1);

/**
 * 服务提供者注册中心。
 *
 * 收集所有 ServiceProvider（类名或实例），按两个阶段统一驱动：
 *   1) bootstrapping()：依次调用每个 provider 的 register()（加载服务定义）
 *   2) booting()      ：依次调用每个 provider 的 boot()    （依赖已就绪后的初始化）
 *
 * 关键点：同一个 provider 在 register 与 boot 阶段使用“同一个实例”，避免状态丢失。
 *
 * 容器实现来自 kode/di（Kode\DI\Container）。
 */
namespace Kode\Core\Provider;

use Kode\DI\Container;

final class ProviderRepository
{
    /** @var array<int, string|ServiceProvider> 待注册的提供者 */
    private array $providers = [];

    /** @var array<class-string, ServiceProvider> 已实例化缓存（保证 register/boot 同一实例） */
    private array $resolved = [];

    public function __construct(private Container $container)
    {
    }

    /** 注册一个服务提供者（传入类名或实例皆可）。 */
    public function register(string|ServiceProvider $provider): void
    {
        $this->providers[] = $provider;
    }

    /** 阶段一：调用所有 provider 的 register()。 */
    public function bootstrapping(): void
    {
        foreach ($this->providers as $provider) {
            $this->resolve($provider)->register();
        }
    }

    /** 阶段二：调用所有 provider 的 boot()。 */
    public function booting(): void
    {
        foreach ($this->providers as $provider) {
            $this->resolve($provider)->boot();
        }
    }

    /**
     * 解析（实例化并缓存）一个服务提供者。
     * @param string|ServiceProvider $provider
     */
    private function resolve(string|ServiceProvider $provider): ServiceProvider
    {
        if ($provider instanceof ServiceProvider) {
            return $provider;
        }
        return $this->resolved[$provider] ??= new $provider($this->container);
    }
}
