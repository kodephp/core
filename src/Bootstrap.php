<?php
declare(strict_types=1);

/**
 * Bootstrap —— 启动引导序列编排器。
 *
 * 负责把零散的“配置 / 容器 / 服务提供者 / 运行时”组装成一个可运行的 App。
 * 它定义了那条你关心的启动链路：
 *
 *   ① 加载配置   loadConfig()        —— 扫描目录 + 内联覆盖，构造 Config 并注入容器
 *   ② 注册服务   registerProviders() —— 按阶段执行各 ServiceProvider 的 register()/boot()
 *   ③ 启动运行时 startRuntime()      —— 默认 cli，再按 options['runtime'] 启用命令叠加高级模式
 *
 * 依赖来源：
 *   - DI 容器      ：kode/di（Kode\DI\Container）
 *   - 运行时抽象   ：kode/runtime（Kode\Runtime\Runtime）
 *   - ServiceProvider / Config：内置于本包 kode/core（对应“【必须自研】App启动器”中允许内置的部分）
 *
 * 注意：Bootstrap 是“启动流程”这一组件的命名，与应用包名（kode/core）无关；
 * 同理 application/bootstrap/core 都只是“内部组件/命名空间名”，不是框架名。
 */
namespace Kode\Core;

use Kode\Core\App;
use Kode\Core\Config\Config;
use Kode\Core\Config\Loader;
use Kode\Core\Provider\ProviderRepository;
use Kode\Core\Runtime\Runtime;
use Kode\DI\Container;

final class Bootstrap
{
    private Container $container;
    private Config $config;
    private ProviderRepository $providers;
    private Runtime $runtime;

    /** @param array<string, mixed> $options */
    public function __construct(private array $options = [])
    {
        // DI 容器来自 kode/di；Config / ServiceProvider 内置于本包
        $this->container = new Container();
        $this->config = new Config();
        $this->providers = new ProviderRepository($this->container);
        $this->runtime = new Runtime($this->container); // 注入容器，供 parallel/distributed 桥接注册服务

        // 把容器注入 kode/facade，使任意继承 Kode\Core\Facade 的静态门面
        // 都能按 id() 从容器解析真实实例（在整个启动周期内可用）。
        Facade::setContainer($this->container);
    }

    /** 执行完整启动序列，返回组装好的 App。 */
    public function run(): App
    {
        $this->loadConfig();        // ① 加载配置
        $this->registerProviders(); // ② 注册服务
        $this->startRuntime();      // ③ 启动运行时

        $app = new App(
            $this->container,
            $this->config,
            $this->runtime,
            $this->basePath(),
        );

        // 把 App、Config、运行时注册进容器，方便服务中类型提示注入
        $this->container->instance(App::class, $app);
        $this->container->instance(Config::class, $this->config);
        $this->container->instance(Runtime::class, $this->runtime);

        // 启动序列收尾：触发所有排队的 booted 回调（此时 App/Config/Runtime 已可注入）
        $app->markBooted();

        return $app;
    }

    /** ① 加载配置：目录扫描 + 内联覆盖，并把 Config 注入容器。 */
    private function loadConfig(): void
    {
        $configPath = $this->options['config_path'] ?? null;
        if (is_string($configPath) && is_dir($configPath)) {
            $this->config->merge((new Loader())->load($configPath));
        }

        // 允许在 boot() 时直接覆盖/补充配置（最高优先级）
        if (isset($this->options['config']) && is_array($this->options['config'])) {
            $this->config->merge($this->options['config']);
        }

        // 把配置注册进容器，供服务提供者在 register() 中读取
        $this->container->instance(Config::class, $this->config);
    }

    /** ② 注册服务：收集 provider → 统一 register() → 统一 boot()。 */
    private function registerProviders(): void
    {
        $list = $this->options['providers'] ?? [];
        foreach ($list as $provider) {
            $this->providers->register($provider);
        }

        $this->providers->bootstrapping(); // 调用所有 register()
        $this->providers->booting();       // 调用所有 boot()（依赖已就绪）
    }

    /** ③ 启动运行时：默认 cli，再按命令叠加高级模式（桥接 kode/runtime）。 */
    private function startRuntime(): void
    {
        $this->runtime->boot(); // 默认启用 cli

        // 启用命令：在 options['runtime'] 中声明的模式依次激活
        foreach ($this->options['runtime'] ?? [] as $mode) {
            $this->runtime->enable($mode);
        }

        // 协程运行时（fiber/swoole/swow）下，自动开启门面上下文安全模式，
        // 使每个协程拥有独立的门面实例缓存，避免跨协程污染（facade 3.2 能力）。
        // 按「当前生效环境」判定：enable 过多个环境时后者已覆盖前者，不能用启用记录。
        if (in_array($this->runtime->environment(), ['fiber', 'swoole', 'swow'], true)) {
            Facade::setAutoContextSafe(true);
        }
    }

    private function basePath(): string
    {
        return $this->options['base_path'] ?? getcwd();
    }
}
