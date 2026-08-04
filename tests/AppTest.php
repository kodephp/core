<?php
declare(strict_types=1);

namespace Kode\Core\Tests;

use Kode\Core\App;
use Kode\Core\Config\Config;
use Kode\Core\Provider\ServiceProvider;
use Kode\DI\Container;
use PHPUnit\Framework\TestCase;

/**
 * kode/core 核心链路测试：启动 → 配置 → DI（kode/di）→ 服务提供者 → 运行时启用命令（桥接 kode/runtime）。
 * 注意：为避免 fork，本测试不启用 process 模式。
 */
final class AppTest extends TestCase
{
    /** 一个简单的服务，构造依赖 Logger（用于验证自动注入）。 */
    private Logger $logger;

    protected function setUp(): void
    {
        $this->logger = new Logger();

        // App::boot() 是单例，测试间需重置，避免上一个测试启动的实例污染本次断言
        $ref = new \ReflectionClass(App::class);
        $prop = $ref->getProperty('instance');
        $prop->setAccessible(true);
        $prop->setValue(null, null);
    }

    public function testBootLoadsConfigAndResolvesServices(): void
    {
        $app = App::boot([
            'config' => [
                'app' => ['name' => 'Tester', 'env' => 'test'],
            ],
            'providers' => [DemoProvider::class],
            'runtime' => ['fiber'],
        ]);

        // 配置加载
        self::assertSame('Tester', $app->config('app.name'));
        self::assertSame('test', $app->config('app.env'));

        // DI（kode/di）：Greeter 依赖 Logger 被自动注入，且为单例
        $greeter1 = $app->make(Greeter::class);
        $greeter2 = $app->make(Greeter::class);
        self::assertSame($greeter1, $greeter2);
        self::assertSame('Hello, Tester!', $greeter1->hello());

        // 运行时（桥接 kode/runtime）：默认 cli + 启用的 fiber
        self::assertTrue($app->runtime->has('cli'));
        self::assertTrue($app->runtime->has('fiber'));
    }

    public function testContainerAutowiresByType(): void
    {
        // DI 容器实现来自 kode/di
        $container = new Container();
        $container->instance(Logger::class, $this->logger);
        $obj = $container->make(NeedsLogger::class);

        self::assertInstanceOf(NeedsLogger::class, $obj);
        self::assertSame($this->logger, $obj->logger);
    }

    public function testConfigDotNotation(): void
    {
        $config = new Config();
        $config->set('a.b.c', 42);
        self::assertTrue($config->has('a.b.c'));
        self::assertSame(42, $config->get('a.b.c'));
        self::assertSame('default', $config->get('x.y', 'default'));
    }

    public function testRuntimeEnableUnknownThrows(): void
    {
        $app = App::boot([
            'config' => ['app' => ['name' => 'X']],
            'runtime' => [],
        ]);
        $this->expectException(\Kode\Core\Exceptions\RuntimeException::class);
        $app->enableRuntime('not-exist');
    }

    public function testAutoBridgeReportsMissingPackages(): void
    {
        $app = App::boot([
            'config' => ['app' => ['name' => 'X']],
            'runtime' => ['fiber'],
        ]);

        // 桥接能力自检：当前未安装对应包
        $bridges = $app->runtime->bridges();
        self::assertArrayHasKey('parallel', $bridges);
        self::assertArrayHasKey('distributed', $bridges);
        self::assertFalse($bridges['parallel']['installed']);
        self::assertFalse($bridges['distributed']['installed']);
        self::assertSame(['kode/parallel'], $bridges['parallel']['packages']);
        self::assertSame(['kode/fibers', 'kode/context'], $bridges['distributed']['packages']);

        // 未安装时启用 parallel：明确提示需 composer require
        try {
            $app->enableRuntime('parallel');
            self::fail('parallel 未安装时应抛异常');
        } catch (\Kode\Core\Exceptions\RuntimeException $e) {
            self::assertStringContainsString('composer require kode/parallel', $e->getMessage());
        }

        // 未安装时启用 distributed：明确提示需 composer require 两个包
        try {
            $app->enableRuntime('distributed');
            self::fail('distributed 未安装时应抛异常');
        } catch (\Kode\Core\Exceptions\RuntimeException $e) {
            self::assertStringContainsString('composer require kode/fibers kode/context', $e->getMessage());
        }
    }

    public function testAppVersionAndConvenienceMethods(): void
    {
        $app = App::boot([
            'config' => ['app' => ['name' => 'Helper']],
            'providers' => [DemoProvider::class],
            'runtime' => ['fiber'],
        ]);

        // 版本号常量
        self::assertSame('1.0.0', App::VERSION);

        // 全局辅助函数
        self::assertSame($app, app());
        self::assertSame('Helper', config('app.name'));
        self::assertInstanceOf(\Kode\Core\Runtime\Runtime::class, runtime());

        // 容器查询
        self::assertTrue($app->has(Greeter::class));
        self::assertFalse($app->has('App\\NoSuchService'));

        // DI 自动注入调用：回调参数按类型从容器解析
        $result = $app->call(fn (Greeter $g) => $g->hello());
        self::assertSame('Hello, Helper!', $result);

        // CLI 环境判定（PHPUnit 运行于 CLI）
        self::assertTrue($app->isCli());
    }
}

// ---------- 测试用辅助类（与应用代码同文件，避免额外加载） ----------

final class Logger
{
    public function info(string $m): void {}
}

final class Greeter
{
    public function __construct(public Logger $logger, public string $name)
    {
    }
    public function hello(): string
    {
        return "Hello, {$this->name}!";
    }
}

final class NeedsLogger
{
    public function __construct(public Logger $logger)
    {
    }
}

final class DemoProvider extends ServiceProvider
{
    public function register(): void
    {
        $name = $this->container->make(Config::class)->get('app.name', 'Kode');
        $this->container->singleton(Greeter::class, fn() => new Greeter($this->container->make(Logger::class), $name));
    }
}
