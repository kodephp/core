<?php
declare(strict_types=1);

/**
 * 运行时管理器（桥接 kode/runtime + 可选 kode 能力包自动桥接）。
 *
 * kode/core 作为“关联 kode 的包的启动器”，本身只负责“启用命令”的编排与对外 API；
 * 真正的多进程 / 多线程 / 协程 / Swoole / Swow 运行能力由 kode/runtime 提供，
 * 而更上层的 parallel（并行）/ distributed（分布式）能力由 kode/parallel、kode/fibers、
 * kode/context 等包在“安装对应依赖后自动桥接” —— 无需改代码，只需 composer require。
 *
 * 启用命令（两种写法）：
 *   App::boot(['runtime' => ['fiber']])   // 在启动序列里声明
 *   $app->enableRuntime('process')         // 启动后动态叠加
 *
 * 支持的模式：
 *   kode/runtime 原生环境（enable 直接映射）：cli / fiber / process / thread / swoole / swow
 *   自动桥接能力（需安装对应包，否则给出明确提示）：
 *     parallel     → kode/parallel
 *     distributed  → kode/fibers + kode/context
 */
namespace Kode\Core\Runtime;

use Kode\Core\Exceptions\RuntimeException;
use Kode\Core\Runtime\Bridge\DistributedBridge;
use Kode\Core\Runtime\Bridge\ParallelBridge;
use Kode\Core\Runtime\Bridge\RuntimeBridgeInterface;
use Kode\DI\Container;
use Kode\Runtime\Runtime as KodeRuntime;
use Kode\Runtime\RuntimeAdapterFactory;

final class Runtime
{
    /** 逻辑模式 → kode/runtime 环境常量 */
    private const MAP = [
        'cli'     => RuntimeAdapterFactory::ENV_CLI,
        'fiber'   => RuntimeAdapterFactory::ENV_FIBER,
        'process' => RuntimeAdapterFactory::ENV_PROCESS,
        'thread'  => RuntimeAdapterFactory::ENV_THREAD,
        'swoole'  => RuntimeAdapterFactory::ENV_SWOOLE,
        'swow'    => RuntimeAdapterFactory::ENV_SWOW,
        'console' => RuntimeAdapterFactory::ENV_CONSOLE,
    ];

    /** 已注册的自动桥接适配器（逻辑模式 → 适配器实例）。 */
    private array $bridges = [];

    /** @var array<string, true> 已启用的模式（环境 + 桥接） */
    private array $enabled = [];

    /** @var array<string, bool> 当前环境/依赖是否支持某模式（含桥接）的缓存 */
    private array $supportedCache = [];

    public function __construct(private ?Container $container = null)
    {
        // 内置两个自动桥接适配器；业务也可 registerBridge() 扩展自定义能力包
        $this->registerBridge(new ParallelBridge());
        $this->registerBridge(new DistributedBridge());
    }

    /** 注册一个自动桥接适配器（可扩展自定义能力包）。 */
    public function registerBridge(RuntimeBridgeInterface $bridge): void
    {
        $this->bridges[$bridge->mode()] = $bridge;
        // 清除该模式的缓存，避免沿用旧结果
        unset($this->supportedCache[$bridge->mode()]);
    }

    /** 列出所有已知的自动桥接能力及其安装状态（用于自检 / 提示）。 */
    /** @return array<string, array{installed: bool, packages: array<int, string>}> */
    public function bridges(): array
    {
        $out = [];
        foreach ($this->bridges as $mode => $bridge) {
            $out[$mode] = [
                'installed' => $bridge->isInstalled(),
                'packages'  => $bridge->composerPackages(),
            ];
        }
        return $out;
    }

    /**
     * 启用命令：激活某个运行时模式。
     * 优先匹配 kode/runtime 环境；否则匹配自动桥接能力（需对应包已安装）。
     *
     * @throws RuntimeException 模式不存在、环境不支持或桥接包未安装。
     */
    public function enable(string $mode): void
    {
        if (isset(self::MAP[$mode])) {
            $this->enableEnv($mode);
            return;
        }
        if (isset($this->bridges[$mode])) {
            $this->enableBridge($mode);
            return;
        }
        $known = array_merge(array_keys(self::MAP), array_keys($this->bridges));
        throw new RuntimeException(
            "未知运行时模式：{$mode}（支持：" . implode('|', $known) . '）'
        );
    }

    /** 桥接 kode/runtime 的某个原生环境。 */
    private function enableEnv(string $mode): void
    {
        if (!$this->supported($mode)) {
            throw new RuntimeException("当前环境不支持运行时模式：{$mode}（需安装对应扩展或依赖）");
        }
        try {
            KodeRuntime::setEnvironment(self::MAP[$mode]);
        } catch (\Kode\Runtime\Exception\UnsupportedOperationException $e) {
            throw new RuntimeException("无法启用运行时模式 {$mode}：{$e->getMessage()}");
        }
        $this->enabled[$mode] = true;
    }

    /** 桥接一个可选能力包（parallel / distributed 等）。 */
    private function enableBridge(string $mode): void
    {
        $bridge = $this->bridges[$mode];
        if (!$bridge->isInstalled()) {
            $pkgs = implode(' ', $bridge->composerPackages());
            throw new RuntimeException(
                "启用运行时模式 {$mode} 需要先安装桥接包：composer require {$pkgs}"
            );
        }
        $bridge->activate($this->container);
        $this->enabled[$mode] = true;
    }

    /** 当前环境是否支持该模式（含自动桥接能力）。 */
    public function supported(string $mode): bool
    {
        if (array_key_exists($mode, $this->supportedCache)) {
            return $this->supportedCache[$mode];
        }
        if (isset(self::MAP[$mode])) {
            try {
                RuntimeAdapterFactory::createForEnvironment(self::MAP[$mode]);
                return $this->supportedCache[$mode] = true;
            } catch (\Throwable) {
                return $this->supportedCache[$mode] = false;
            }
        }
        if (isset($this->bridges[$mode])) {
            return $this->supportedCache[$mode] = $this->bridges[$mode]->isInstalled();
        }
        return $this->supportedCache[$mode] = false;
    }

    /** 是否已启用某模式（含桥接）。 */
    public function has(string $mode): bool
    {
        return isset($this->enabled[$mode]);
    }

    /** @return array<string, true> 所有已启用的模式（环境 + 桥接）。 */
    public function enabled(): array
    {
        return $this->enabled;
    }

    /** 默认启动：若未显式启用任何 kode/runtime 环境，则启用 cli。 */
    public function boot(): void
    {
        if (!$this->has('cli')) {
            $this->enable('cli');
        }
    }

    /** 当前运行时环境名称（来自 kode/runtime）。 */
    public function getName(): string
    {
        return KodeRuntime::getName();
    }

    /**
     * 在当前运行时中异步执行一个任务。
     *
     * @return mixed CLI 模式直接返回任务结果；Fiber/Swoole 等返回协程句柄。
     */
    public function async(callable $task): mixed
    {
        return KodeRuntime::async($task);
    }

    /** 等待所有异步任务完成（协程 / 进程模式下有意义）。 */
    public function wait(): void
    {
        KodeRuntime::wait();
    }

    /** 注册当前上下文退出时的清理回调。 */
    public function defer(callable $callback): void
    {
        KodeRuntime::defer($callback);
    }

    /** 在当前运行时中休眠。 */
    public function sleep(float $seconds): void
    {
        KodeRuntime::sleep($seconds);
    }

    /** fork 一个子进程（仅 process 环境可用）。 */
    public function fork(callable $callback): int
    {
        return KodeRuntime::fork($callback);
    }

    /** 创建一个通道（协程间通信）。 */
    public function channel(int $capacity = 0): \Kode\Runtime\ChannelInterface
    {
        return KodeRuntime::createChannel($capacity);
    }

    /**
     * 运行一个任务并返回其结果（桥接当前激活的运行时）。
     *
     * 通过闭包捕获返回值，因此 cli/fiber/swoole/swow 等“进程内”运行时可拿到结果；
     * 多进程（process）模式下子进程内存独立，返回值不会回传父进程（适合副作用型任务）。
     */
    public function run(callable $task): mixed
    {
        $captured = null;
        KodeRuntime::async(function () use ($task, &$captured): void {
            $captured = $task();
        });
        KodeRuntime::wait();
        return $captured;
    }

    /**
     * 批量运行一组任务并收集结果（演示协程 / 并发调度）。
     *
     * @param array<int, callable> $tasks
     * @return array<int, mixed>
     */
    public function runTasks(array $tasks): array
    {
        $results = [];
        foreach ($tasks as $i => $task) {
            $results[$i] = $this->run($task);
        }
        return $results;
    }
}
