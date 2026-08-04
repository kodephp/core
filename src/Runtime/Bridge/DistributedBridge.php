<?php
declare(strict_types=1);

/**
 * distributed 桥接适配器 —— 自动桥接 kode/fibers + kode/context。
 *
 * 分布式能力由两层叠加：
 *   - kode/fibers ：Fiber 协程调度 + 分布式事务
 *   - kode/context：跨机器协程上下文透传
 * 两者都安装时才视为可用；启用时分别把核心服务注册进容器。
 *
 * activate() 同样以 class_exists 做最佳努力注册（已绑定则不覆盖）。
 */
namespace Kode\Core\Runtime\Bridge;

use Kode\DI\Container;

final class DistributedBridge extends AbstractRuntimeBridge
{
    /** @var array<int, string> */
    private const PACKAGES = ['kode/fibers', 'kode/context'];

    /** @var array<int, string> */
    private const CANDIDATES = [
        'Kode\\Fibers\\Scheduler',
        'Kode\\Fibers\\Transaction',
        'Kode\\Context\\Context',
        'Kode\\Context\\CoroutineContext',
    ];

    public function mode(): string
    {
        return 'distributed';
    }

    /** @return array<int, string> */
    public function composerPackages(): array
    {
        return self::PACKAGES;
    }

    public function activate(?Container $container): void
    {
        if ($container === null || !$this->isInstalled()) {
            return;
        }

        foreach (self::CANDIDATES as $cls) {
            if (class_exists($cls) && !$container->has($cls)) {
                $container->singleton($cls);
            }
        }
    }
}
