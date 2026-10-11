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
use Kode\Core\Context;
use Kode\Core\Runtime\Runtime;
use Kode\DI\Container;

final class App
{
    /** 当前发布版本（与 git tag vX.Y.Z 保持一致）。 */
    public const VERSION = '2.0.2';

    private static ?App $instance = null;

    /** 标记是否已结束启动序列（booted 回调据此决定立即执行还是入队）。 */
    private bool $booted = false;

    /** 启动完成（markBooted）后执行的回调队列。 */
    private array $bootedCallbacks = [];

    /** 应用终止（PHP shutdown）时执行的回调队列。 */
    private array $terminatingCallbacks = [];

    /** 应用事件监听器：事件名 → 监听器队列。 */
    private array $listeners = [];

    /** 应用上下文封装实例（桥接 kode/context，懒加载复用）。 */
    private ?Context $contextInstance = null;

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

        // 注册应用终止回调：PHP 进程结束时（shutdown）自动执行所有 terminating 回调。
        // 数组形式捕获当前实例，即便后续 singleton 被测试重置也不会丢失。
        register_shutdown_function([self::$instance, 'callTerminatingCallbacks']);

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

    /** 是否为控制台（CLI）环境，等价于 isCli()（语义化别名）。 */
    public function runningInConsole(): bool
    {
        return $this->isCli();
    }

    /**
     * 读取 / 判断当前运行环境。
     *   environment()              → 返回配置中的 app.env（默认 'production'）
     *   environment('testing')     → 当前为 testing 返回 true，否则 false
     *   environment('local','testing') → 命中任一即 true
     *   environment(['local','testing']) → 命中数组内任一即 true
     */
    public function environment(string|array ...$envs): string|bool
    {
        $current = $this->config->get('app.env', 'production');
        if ($envs === []) {
            return $current;
        }
        foreach ($envs as $env) {
            $list = is_array($env) ? $env : [$env];
            if (in_array($current, $list, true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * 应用基路径。
     *   basePath()          → 返回根目录（boot 时由 base_path 选项决定，缺省 getcwd()）
     *   basePath('a/b.php') → 根目录拼接相对路径
     */
    public function basePath(string $path = ''): string
    {
        $base = rtrim($this->basePath, '/\\');
        return $path === '' ? $base : $base . '/' . ltrim($path, '/\\');
    }

    /** 应用源码路径（basePath/src）。 */
    public function path(string $path = ''): string
    {
        return $this->basePath('src' . ($path === '' ? '' : '/' . ltrim($path, '/\\')));
    }

    /** 返回当前版本号（等价于 App::VERSION）。 */
    public function version(): string
    {
        return self::VERSION;
    }

    /**
     * 获取应用上下文封装（桥接 kode/context）。
     * 用于协程/分布式上下文读写、作用域隔离与 W3C 链路追踪透传。
     *   $app->context()->startTrace();
     *   $app->context()->set('tenant_id', 42);
     *   $app->context()->toHeaders();
     */
    public function context(): Context
    {
        return $this->contextInstance ??= new Context();
    }

    /**
     * 注册“启动完成后”执行的回调。
     * - 若应用已启动（booted=true），则立即以 DI 注入方式执行；
     * - 否则入队，待 Bootstrap 在启动序列末尾统一触发（markBooted）。
     *
     * 回调参数会按类型从容器自动解析（可类型提示 App / Config / Runtime）。
     */
    public function booted(callable $callback): static
    {
        if ($this->booted) {
            $this->container->call($callback);
            return $this;
        }
        $this->bootedCallbacks[] = $callback;
        return $this;
    }

    /**
     * 由 Bootstrap 在启动序列末尾调用：执行所有排队的 booted 回调并标记 booted=true。
     * 此后通过 booted() 注册的回调将立即执行。
     */
    public function markBooted(): void
    {
        $this->booted = true;
        foreach ($this->bootedCallbacks as $callback) {
            $this->container->call($callback);
        }
        $this->bootedCallbacks = [];
    }

    /**
     * 注册“应用终止时（PHP shutdown）”执行的回调。
     * App::boot() 已自动注册 register_shutdown_function，进程退出前会自动 callTerminatingCallbacks()。
     * 回调参数按类型从容器自动解析。
     */
    public function terminating(callable $callback): static
    {
        $this->terminatingCallbacks[] = $callback;
        return $this;
    }

    /**
     * 执行所有 terminating 回调（幂等：执行后清空，重复调用为无操作）。
     *
     * 本方法由 register_shutdown_function 在进程退出前触发，属最后一道清理：
     * 先摘牌再执行（抛错也不会重复触发），单个回调抛错只记录不中断其余回调，
     * 否则一个失败的清理会连带把后面的资源释放全部跳过。
     */
    public function callTerminatingCallbacks(): void
    {
        $callbacks = $this->terminatingCallbacks;
        $this->terminatingCallbacks = [];

        foreach ($callbacks as $callback) {
            try {
                $this->container->call($callback);
            } catch (\Throwable $e) {
                error_log('[kode] terminating 回调失败：' . $e::class . ': ' . $e->getMessage());
            }
        }
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

    /**
     * 开启门面上下文安全模式（协程隔离）。
     *
     * 当应用运行在 Fiber / Swoole / Swow 等协程运行时时建议开启，
     * 使每个协程拥有独立的门面实例缓存，避免跨协程污染（kode/facade 3.2 能力）。
     * 默认在 App::boot() 检测到协程运行时后自动开启，本方法可用于运行时动态控制。
     *
     *   $app->enableFacadeContextSafe();        // 开启
     *   $app->enableFacadeContextSafe(false);    // 关闭
     */
    public function enableFacadeContextSafe(bool $on = true): static
    {
        Facade::setAutoContextSafe($on);
        return $this;
    }

    // ------------------------------------------------------------------
    // 应用事件总线（运行时组件间解耦通信）
    // ------------------------------------------------------------------

    /**
     * 注册事件监听器。
     *   $app->listen('order.created', fn ($order) => ...);
     */
    public function listen(string $event, callable $listener): static
    {
        $this->listeners[$event][] = $listener;
        return $this;
    }

    /** 是否存在某事件的监听器。 */
    public function hasListeners(string $event): bool
    {
        return !empty($this->listeners[$event]);
    }

    /**
     * 移除监听器：不传 $listener 则清空该事件全部监听器；
     * 传 $listener 则仅移除与之相同的闭包/可调用对象。
     */
    public function forget(string $event, ?callable $listener = null): static
    {
        if ($listener === null) {
            unset($this->listeners[$event]);
            return $this;
        }
        if (isset($this->listeners[$event])) {
            $this->listeners[$event] = array_values(array_filter(
                $this->listeners[$event],
                static fn (callable $l): bool => $l !== $listener
            ));
        }
        return $this;
    }

    /**
     * 派发事件：按注册顺序逐个调用监听器，payload 以可变参数形式传入。
     * 返回各监听器的返回值数组（便于“返回值聚合”场景）。
     *
     *   $results = $app->dispatch('order.created', $order);
     *
     * @return array<int, mixed>
     */
    public function dispatch(string $event, mixed ...$payload): array
    {
        if (!isset($this->listeners[$event])) {
            return [];
        }
        $results = [];
        foreach ($this->listeners[$event] as $listener) {
            $results[] = $listener(...$payload);
        }
        return $results;
    }

    // ------------------------------------------------------------------
    // 维护模式（文件标记，常用于发布/紧急停机）
    // ------------------------------------------------------------------

    /** 维护模式标记文件相对于基路径的位置。 */
    private const DOWN_FILE = 'storage/framework/down';

    /** 进入维护模式：在 storage/framework/down 写入 JSON 标记（含时间与可选提示）。 */
    public function down(string $message = ''): static
    {
        $path = $this->basePath(self::DOWN_FILE);
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($path, json_encode([
            'time'    => time(),
            'message' => $message,
        ], JSON_UNESCAPED_UNICODE));
        return $this;
    }

    /** 退出维护模式：删除标记文件。 */
    public function up(): static
    {
        $path = $this->basePath(self::DOWN_FILE);
        if (is_file($path)) {
            unlink($path);
        }
        return $this;
    }

    /** 是否处于维护模式（标记文件是否存在）。 */
    public function isDownForMaintenance(): bool
    {
        return is_file($this->basePath(self::DOWN_FILE));
    }

    // ------------------------------------------------------------------
    // 运行环境辅助判定
    // ------------------------------------------------------------------

    /** 是否处于单元测试环境（检测 PHPUnit / APP_ENV=testing）。 */
    public function runningUnitTests(): bool
    {
        return class_exists(\PHPUnit\Framework\TestCase::class)
            || defined('PHPUNIT_TESTSUITE')
            || getenv('APP_ENV') === 'testing';
    }
}
