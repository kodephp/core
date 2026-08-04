# kode/core —— 应用启动器（App Launcher）

> `App::boot()` → 加载配置 → 注册服务 → 启动运行时

`kode/core` 是 Kode 生态的**应用启动器（App Launcher）**，专门用来启动“关联 kode 的包”。
它提供应用启动所需的「三件套」：

- **App 启动器**：`App::boot()` 单一入口，对应你说的 “Application 启动器”。
- **ServiceProvider**：服务注册 / 启动两段式生命周期（**内置于本包**）。
- **Config**：点号路径配置仓库 + 目录加载器（**内置于本包**）。
- **DI 容器**：由 `kode/di` 提供（高性能、属性注入、生命周期管理、协程上下文隔离）。
- **Runtime 运行时抽象 + 启用命令**：由 `kode/runtime` 提供，通过 `enable()` 命令式叠加
  多进程 / 多线程 / 协程 / Swoole / Swow 能力。

**「必须自研」的范围**：只有本包的核心启动器（Bootstrap + App 门面）+ 内置的 ServiceProvider
与 Config 是自研；DI 桥接 `kode/di`，运行时桥接 `kode/runtime`，**不重复造轮子**。

---

## 一、安装

```bash
composer require kode/core
```

（当前在仓库内开发，可直接 `composer install` 生成自动加载。）

`kode/core` 会自动拉取 `kode/di`（DI 容器）与 `kode/runtime`（运行时抽象）。

---

## 二、快速开始

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';

use Kode\Core\App;
use Kode\Core\Config\Config;
use Kode\Core\Provider\ServiceProvider;

// 1) 业务服务
final class Greeter
{
    public function __construct(private string $name) {}
    public function hello(): string { return "Hello, {$this->name}!"; }
}

// 2) 服务提供者
final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $name = $this->container->make(Config::class)->get('app.name', 'Kode');
        $this->container->singleton(Greeter::class, fn() => new Greeter($name));
    }
}

// 3) 一行启动
$app = App::boot([
    'base_path'   => __DIR__,
    'config_path' => __DIR__ . '/config',   // 目录下的 *.php 自动加载为配置
    'providers'   => [AppServiceProvider::class],
    'runtime'     => ['fiber'],             // 启用命令：叠加协程运行时
]);

echo $app->make(Greeter::class)->hello();   // Hello, Kode!
echo $app->config('app.name');              // Kode
```

运行演示：`php examples/bootstrap.php`

### 全局辅助函数与 App 便捷方法

启动后，除了 `App` 实例本身，还提供三个全局函数与若干便捷方法：

```php
app();                 // 返回已启动的 App 实例（等价于 App::getInstance()）
app(Greeter::class);   // 等价于 $app->make(Greeter::class)
config('app.name');    // 读配置（等价于 $app->config('app.name')）
runtime();             // 返回 Runtime 实例（等价于 $app->runtime）

// App 便捷方法
App::VERSION;          // 当前版本号，如 '1.0.0'（与 git tag 保持一致）
$app->has(Greeter::class);            // 容器中是否存在该绑定
$app->call(fn (Greeter $g) => $g->hello());  // 以 DI 自动注入方式调用回调
$app->isCli();                        // 是否运行在 CLI / PHPDBG 下
$app->enableRuntime('process');       // 链式启用运行时（等价于 $app->runtime->enable()）
```

---

## 三、启动序列（Bootstrap）

`App::boot()` 内部由 `Bootstrap` 编排三步：

```
① 加载配置   loadConfig()
       └─ 扫描 config_path 目录 + 合并 boot() 内联配置 → 构造 Config → 注入容器

② 注册服务   registerProviders()
       └─ 依次调用各 ServiceProvider::register()（注册绑定）
          再依次调用各 ServiceProvider::boot()（依赖已就绪后的初始化）
          （容器实现来自 kode/di）

③ 启动运行时 startRuntime()
       └─ 默认启用 cli（桥接 kode/runtime）
          → 按 options['runtime'] 逐个 enable()（启用命令叠加高级模式）
```

`Bootstrap` 只是“启动流程”这一组件的命名，与应用包名 `kode/core` 无关——这正是你关心的「组件名 ≠ 框架名」。

---

## 四、DI 容器（来自 kode/di）

`kode/core` 不重复造轮子，DI 直接复用 [`kode/di`](https://github.com/kodephp/di)：高性能 PHP 8.1+ 容器，
支持属性注入、生命周期管理（singleton / prototype / lazy / contextual）、协程上下文隔离，兼容 PSR-11。

```php
// 容器由 kode/core 在启动时创建（Kode\DI\Container），可通过 App 取得
$container = $app->container;

// 绑定抽象到实现
$container->bind(LoggerInterface::class, FileLogger::class);

// 单例
$container->singleton(Greeter::class, fn($c) => new Greeter($c->make(LoggerInterface::class)));

// 直接存入实例
$container->instance(Config::class, $config);

// 解析（构造函数依赖自动按类型注入）
$greeter = $container->make(Greeter::class);
```

- 兼容 PSR-11 语义（`get()` / `has()`）。
- 支持 `#[Inject]` 属性标记（来自 `kode/di`：`Kode\DI\Attributes\Inject`）。

---

## 五、ServiceProvider（内置）

```php
use Kode\Core\Provider\ServiceProvider;

final class CacheServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // 注册阶段：登记绑定（其它服务可能尚未就绪）
        $this->container->singleton(\Psr\SimpleCache\CacheInterface::class, RedisCache::class);
    }

    public function boot(): void
    {
        // 启动阶段：依赖已就绪，可做初始化（连接、订阅等）
    }
}
```

`ServiceProvider` 继承自 `kode/di` 的 `ServiceProvider`，因此直接拥有 `bind` / `singleton` /
`instance` / `alias` / `extend` 等能力；`register()` 与 `boot()` 对同一 provider 使用**同一实例**，避免状态丢失。

---

## 六、运行时与「启用命令」（多进程 / 多线程 / 协程 / Swoole / Swow / 并行 / 分布式）

`Runtime` 是启用命令中枢，底层桥接 [`kode/runtime`](https://github.com/kodephp/runtime)。
默认以 `cli` 启动（零依赖），再按需启用高级模式：

```php
$app = App::boot([
    'runtime' => ['fiber'],          // 启动时启用协程
]);

$app->enableRuntime('process');      // 运行时再启用多进程（链式）
```

| 启用命令 | 能力 | 底层 | 当前环境 / 依赖不支持时 |
|----------|------|------|--------------------------|
| `enable('cli')` | CLI / FPM 基础运行时 | kode/runtime `cli` | —（始终可用） |
| `enable('fiber')` | Fiber 协程（PHP 8.1+ 原生） | kode/runtime `fiber` | 抛出明确提示 |
| `enable('process')` | 多进程（pcntl_fork） | kode/runtime `process` | 抛出明确提示 |
| `enable('thread')` | 多线程（pthreads） | kode/runtime `thread` | 抛出明确提示 |
| `enable('swoole')` | Swoole 协程服务器 | kode/runtime `swoole` | 抛出明确提示 |
| `enable('swow')` | Swow 协程引擎 | kode/runtime `swow` | 抛出明确提示 |
| `enable('parallel')` | 并行 / 多线程（Runtime/Task/Future/Cluster） | **自动桥接 kode/parallel** | 提示 `composer require kode/parallel` |
| `enable('distributed')` | 分布式（协程调度 + 跨机器上下文透传） | **自动桥接 kode/fibers + kode/context** | 提示 `composer require kode/fibers kode/context` |

### 自动桥接适配器（重点）

`parallel` 与 `distributed` 不是 kode/runtime 的原生环境，而是**更上层的可选能力包**。
`kode/core` 通过 `src/Runtime/Bridge/` 下的适配器实现“安装即桥接”：

- 你只需 `composer require kode/parallel`（或 `kode/fibers kode/context`），无需改任何业务代码；
- 启动时 `enable('parallel')` / `enable('distributed')` 会**自动检测该包是否已安装**（基于
  Composer InstalledVersions，比 class_exists 更稳健），并把包内核心服务注册进 DI 容器；
- 若包未安装，`enable()` 会**明确提示需要执行哪条 composer require**，绝不静默失败。

```php
$app = App::boot([
    'runtime' => ['fiber', 'parallel'],   // 协程 + 并行（要求已 composer require kode/parallel）
]);

// 自检：当前环境/依赖下各桥接能力的可用状态
foreach ($app->runtime->bridges() as $mode => $info) {
    printf("%s: %s (需 %s)\n",
        $mode,
        $info['installed'] ? '可用' : '未安装',
        implode(' ', $info['packages']),
    );
}

// 未安装时启用会抛清晰异常：
// RuntimeException: 启用运行时模式 parallel 需要先安装桥接包：composer require kode/parallel
// $app->enableRuntime('parallel');
```

> **设计原则**：`enable()` 命令统一了“原生运行时环境”与“可选能力包”两种扩展路径——前者映射到
> kode/runtime 的环境常量，后者交给桥接适配器。需要多进程就用 `enable('process')`，需要协程就用
> `enable('fiber'|'swoole'|'swow')`，需要并行/分布式就 `composer require` 对应包后 `enable('parallel'|'distributed')`。

并发任务演示（运行在当前激活的运行时上）：

```php
$results = $app->runtime->runTasks([
    fn() => 'task-1@' . $app->runtime->getName(),
    fn() => 'task-2@coroutine',
]);
// 进程内运行时（cli/fiber/swoole/swow）可拿到各任务返回值；
// 多进程（process）子进程内存独立，返回值不回传父进程（适合副作用型任务）。
```

`Runtime` 还透传了 kode/runtime 的核心能力：`async()` / `wait()` / `defer()` / `sleep()` /
`fork()` / `channel()` / `getName()`。

---

## 七、与 kode 生态的关系

```
kode/core        ← 本包：App 启动器 + 内置 ServiceProvider/Config（必须自研的内核）
   │  桥接（按需启用命令）
   ├── kode/di          DI 容器（bind/singleton/属性注入/协程上下文隔离）
   ├── kode/runtime     统一运行时抽象（cli/fiber/process/thread/swoole/swow）
   ├── kode/process     Master-Worker 多进程（建议随 enable('process') 安装）
   ├── kode/parallel    多线程/并行（Runtime/Task/Future/Cluster）
   ├── kode/fibers      Fiber 协程调度 + 分布式事务
   └── kode/context     跨机器协程上下文透传（分布式）
```

`kode/core` 是“地基 + 启动器”，其它 kode 包是“可插拔的能力插件”。业务应用只需依赖 `kode/core`，
需要哪种运行时就在 `boot()` 的 `runtime` 里声明、按需 `composer require` 对应包即可。

---

## 八、目录结构

```
application/                 # 开发目录（包名是 kode/core，目录名与包名可不同）
├── composer.json            # name: kode/core, php >= 8.3, require: kode/di + kode/runtime
├── LICENSE                  # MIT（Copyright (c) kode, zyk）
├── CHANGELOG.md             # 版本变更记录（v1.0.0 起）
├── .gitignore               # 忽略 vendor / composer.lock / .workbuddy 等
├── .github/workflows/tests.yml  # CI：PHP 8.3 下运行 PHPUnit
├── phpunit.xml.dist
├── src/
│   ├── App.php              # 启动器门面（App::boot()）+ VERSION / call / has / isCli
│   ├── Bootstrap.php        # 启动序列编排（加载配置→注册服务→启动运行时）
│   ├── Config/              # Config 仓库 + Loader 加载器（内置）
│   ├── Provider/            # ServiceProvider 抽象（桥接 kode/di）+ ProviderRepository（内置）
│   ├── Runtime/             # Runtime 管理器（桥接 kode/runtime）
│   │   └── Bridge/           # 自动桥接适配器（parallel→kode/parallel、distributed→kode/fibers+kode/context）
│   ├── Exceptions/          # CoreException / RuntimeException 体系
│   └── Support/             # helpers.php（app() / config() / runtime() 全局函数）
├── examples/                # bootstrap.php 演示 + config/
└── tests/                   # PHPUnit 测试
```

---

## 九、PHP 版本与保留字

- 要求 **PHP >= 8.3**，使用了 `readonly`、枚举式常量、`match`、属性（Attribute）、First-class callable 等现代特性。
- 所有公开类名均避开 PHP 保留字与内置类/函数（`App` / `Bootstrap` / `Config` / `Provider` / `Runtime` 等均为安全普通类名）。
