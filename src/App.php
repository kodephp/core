<?php
declare(strict_types=1);

/**
 * App —— 应用启动器（门面 / 单例）。
 *
 * 整个包对外的唯一入口：App::boot()
 *   → 加载配置（Config，内置于本包）
 *   → 注册服务（ServiceProvider + kode/di 容器）
 *   → 启动运行时（桥接 kode/runtime）
 *
 * 关于命名：
 *   - App 是 application 的简化，作为启动器/门面类名，最简洁且不与 PHP 保留字冲突。
 *   - 包名是 composer.json 里的 kode/core，二者是两回事。
 *   - 真正的“启动流程”由 Bootstrap 组件负责；core 指本包的内核
 *     （Config + ServiceProvider + 对 kode/di、kode/runtime 的桥接）。
 */
namespace Kode\Core;

use Kode\Core\Config\Config;
use Kode\Core\Runtime\Runtime;
use Kode\DI\Container;

final class App
{
    /** 当前发布版本（与 git tag vX.Y.Z 保持一致）。 */
    public const VERSION = '1.0.0';

    private static ?App $instance = null;

    public function __construct(
        public readonly Container $container,
        public readonly Config $config,
        public readonly Runtime $runtime,
        public readonly string $basePath,
    ) {
    }

    /**
     * 应用启动入口。
     *
     * @param array{
     *     base_path?: string,
     *     config_path?: string,
     *     providers?: array<int, class-string|object>,
     *     config?: array<string, mixed>,
     *     runtime?: array<int, string>
     * } $options
     *
     * 示例：
     *   $app = App::boot([
     *       'base_path'   => __DIR__,
     *       'config_path' => __DIR__ . '/config',
     *       'providers'   => [AppServiceProvider::class],
     *       'runtime'     => ['fiber'], // 启用命令：叠加协程运行时
     *   ]);
     */
    public static function boot(array $options = []): App
    {
        if (self::$instance !== null) {
            return self::$instance; // 单例：重复 boot 返回已启动实例
        }

        $bootstrap = new Bootstrap($options);
        self::$instance = $bootstrap->run();
        return self::$instance;
    }

    /** 获取已启动的 App 单例（未启动时返回 null）。 */
    public static function getInstance(): ?App
    {
        return self::$instance;
    }

    /** 从容器解析一个服务（委托 kode/di）。 */
    public function make(string $abstract, array $parameters = []): mixed
    {
        return $this->container->make($abstract, $parameters);
    }

    /** 便捷读取配置。 */
    public function config(string $key, mixed $default = null): mixed
    {
        return $this->config->get($key, $default);
    }

    /** 判断容器中是否存在某个绑定（委托 kode/di）。 */
    public function has(string $id): bool
    {
        return $this->container->has($id);
    }

    /**
     * 以依赖注入方式调用一个回调 / [类, 方法]（委托 kode/di 的 call()）。
     * 回调的参数会按类型自动从容器解析，未提供的参数可用 $parameters 覆盖。
     *
     *   $app->call(fn (Greeter $g) => $g->hello());
     */
    public function call(callable $callback, array $parameters = []): mixed
    {
        return $this->container->call($callback, $parameters);
    }

    /** 是否运行在 CLI / PHPDBG 下（控制台环境）。 */
    public function isCli(): bool
    {
        return \PHP_SAPI === 'cli' || \PHP_SAPI === 'phpdbg';
    }

    /**
     * 链式启用运行时模式（启用命令的另一种写法）。
     *   App::boot([...])->enableRuntime('process')
     */
    public function enableRuntime(string $name): App
    {
        $this->runtime->enable($name);
        return $this;
    }
}
