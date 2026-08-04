# Changelog

本文件记录 kode/core 的版本变更。版本号遵循 [语义化版本 2.0.0](https://semver.org/lang/zh-CN/)，
每个正式版本都对应一个 `vX.Y.Z` 的 git tag。

## [1.0.0] - 2026-08-04

首个正式版本。作为 Kode 生态的**应用启动器（App Launcher）**，用于启动“关联 kode 的包”。

### 新增

- **App 启动器**：`App::boot()` 单一入口，启动链路为「加载配置 → 注册服务 → 启动运行时」。
- **DI 容器**：桥接 [`kode/di`](https://github.com/kodephp/di)（高性能、属性注入、协程上下文隔离）。
- **运行时抽象**：桥接 [`kode/runtime`](https://github.com/kodephp/runtime)，通过 `enable()` 启用命令叠加
  `cli` / `fiber` / `process` / `thread` / `swoole` / `swow` 六种原生环境。
- **自动桥接适配器**（`src/Runtime/Bridge/`）：`parallel`（→ `kode/parallel`）、
  `distributed`（→ `kode/fibers` + `kode/context`）。只需 `composer require` 对应包即可自动桥接，
  未安装时给出明确的 `composer require` 提示。
- **ServiceProvider**：内置（继承 `kode/di` 的 `ServiceProvider`），`register()` / `boot()` 两段式生命周期。
- **Config**：内置点号路径配置仓库 + 目录加载器。
- **全局辅助函数**：`app()` / `config()` / `runtime()`。
- **App 便捷方法**：`App::VERSION`、`make()`、`config()`、`has()`、`call()`（DI 自动注入调用）、
  `isCli()`、`enableRuntime()`。
- **运行时能力透传**：`async()` / `wait()` / `defer()` / `sleep()` / `fork()` / `channel()` /
  `getName()` / `run()` / `runTasks()`。
- **CI**：GitHub Actions 在 PHP 8.3 下运行 PHPUnit。

### 要求

- PHP >= 8.3
- 依赖 `kode/di:^1.0`、`kode/runtime:^1.2`

[1.0.0]: https://github.com/kodephp/core/releases/tag/v1.0.0
