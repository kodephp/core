<?php
declare(strict_types=1);

/**
 * 桥接适配器基类：统一实现“包是否已安装”的探测逻辑。
 *
 * 探测基于 Composer 运行期的 InstalledVersions（composer 2 自带，位于 vendor/composer），
 * 比 class_exists 更稳健——只要执行过 composer require，无论具体类名如何都能正确识别。
 */
namespace Kode\Core\Runtime\Bridge;

abstract class AbstractRuntimeBridge implements RuntimeBridgeInterface
{
    /** {@inheritdoc} */
    public function isInstalled(): bool
    {
        foreach ($this->composerPackages() as $package) {
            if (!self::packageInstalled($package)) {
                return false;
            }
        }
        return true;
    }

    /**
     * 通过 Composer InstalledVersions 判断包是否已安装。
     *
     * 双重保护：先确认 InstalledVersions 类与方法存在，避免在某些“非标准自动加载”
     * 环境下触发 fatal error。
     */
    protected static function packageInstalled(string $package): bool
    {
        return class_exists(\Composer\InstalledVersions::class)
            && method_exists(\Composer\InstalledVersions::class, 'isInstalled')
            && \Composer\InstalledVersions::isInstalled($package);
    }
}
