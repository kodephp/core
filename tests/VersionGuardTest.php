<?php

declare(strict_types=1);

namespace Kode\Core\Tests;

use Kode\Core\App;
use PHPUnit\Framework\TestCase;

/**
 * 版本防漂移：本包无 composer.json version（由 git tag 驱动发布），
 * 因此 App::VERSION 必须与最近的 tag 对齐，否则应用侧 composer update 后
 * 读到的版本号与源码里的常量会各说各话。
 */
final class VersionGuardTest extends TestCase
{
    public function testVersionConstantMatchesLatestTag(): void
    {
        $root = dirname(__DIR__);
        if (!is_dir($root . '/.git') || !function_exists('exec')) {
            $this->markTestSkipped('非 git 检出（dist 安装），跳过 tag 对照');
        }

        $output = '';
        \exec('git -C ' . escapeshellarg($root) . ' describe --tags --abbrev=0 2>/dev/null', $output, $code);
        $tag = trim((string) ($output[0] ?? ''));
        if ($code !== 0 || $tag === '') {
            $this->markTestSkipped('仓库尚无可用 tag，跳过对照');
        }

        self::assertSame(
            ltrim($tag, 'v'),
            App::VERSION,
            "App::VERSION 与最近的 tag（{$tag}）不一致：发版时漏改常量"
        );
    }

    public function testVersionConstantIsSemanticAndMatchesAccessor(): void
    {
        self::assertMatchesRegularExpression(
            '/^\d+\.\d+\.\d+$/',
            App::VERSION,
            'VERSION 必须是 X.Y.Z（不带 v 前缀 / 后缀）'
        );

        $app = App::boot([]);
        self::assertSame(App::VERSION, $app->version(), 'version() 必须回读同一常量');
    }
}
