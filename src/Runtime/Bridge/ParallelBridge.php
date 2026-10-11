<?php
declare(strict_types=1);

/**
 * parallel 桥接适配器 —— 自动桥接 kode/parallel。
 *
 * kode/parallel 提供多线程 / 并行（Runtime / Task / Future / Cluster）。
 * 当项目已 composer require kode/parallel 时，enable('parallel') 会自动把
 * 其核心服务注册进 DI 容器；否则给出明确的安装提示，绝不静默失败。
 *
 * 注：具体服务类名以 kode/parallel 实际发布为准；activate() 以 class_exists 做
 * 最佳努力注册（已绑定则不覆盖），即使类名有出入，模式仍会标记为“已启用”。
 */
namespace Kode\Core\Runtime\Bridge;

use Kode\DI\Container;

final class ParallelBridge extends AbstractRuntimeBridge
{
    private const PACKAGE = 'kode/parallel';

    #[\Override]
    public function mode(): string
    {
        return 'parallel';
    }

    /** @return array<int, string> */
    #[\Override]
    public function composerPackages(): array
    {
        return [self::PACKAGE];
    }

    #[\Override]
    public function activate(?Container $container): void
    {
        if ($container === null || !$this->isInstalled()) {
            return;
        }

        // 候选核心类：存在即注册为单例（已手动绑定则不覆盖）
        $candidates = [
            'Kode\\Parallel\\Parallel',
            'Kode\\Parallel\\Task',
            'Kode\\Parallel\\Future',
            'Kode\\Parallel\\Cluster',
            'Kode\\Parallel\\Worker',
        ];
        foreach ($candidates as $cls) {
            if (class_exists($cls) && !$container->has($cls)) {
                $container->singleton($cls);
            }
        }
    }
}
