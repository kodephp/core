<?php
declare(strict_types=1);

/**
 * 运行时桥接适配器接口。
 *
 * 当一个“逻辑运行时能力”（如 parallel 并行 / distributed 分布式）由某个 kode 包提供、
 * 且当前项目已通过 composer require 安装该包时，本适配器负责将其“自动桥接”进 kode/core：
 *   - 探测包是否已安装（基于 Composer InstalledVersions，比 class_exists 更稳健）
 *   - 激活：把包内的核心服务注册进 App 的 DI 容器
 *   - 标记该逻辑模式为「已启用」
 *
 * 若包未安装，Runtime::enable() 会抛出清晰的安装提示，绝不静默失败。
 */
namespace Kode\Core\Runtime\Bridge;

use Kode\DI\Container;

interface RuntimeBridgeInterface
{
    /** 逻辑模式名（enable 命令使用的名字，如 'parallel' / 'distributed'）。 */
    public function mode(): string;

    /** 启用该模式所需的 composer 包（用于缺失时提示安装）。 */
    /** @return array<int, string> */
    public function composerPackages(): array;

    /** 包是否已全部安装可用。 */
    public function isInstalled(): bool;

    /**
     * 激活：把桥接包的核心服务注册进容器。
     * 容器可能为空（如仅做声明式启用），实现需容忍 null。
     */
    public function activate(?Container $container): void;
}
