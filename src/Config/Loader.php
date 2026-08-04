<?php
declare(strict_types=1);

/**
 * 配置文件加载器。
 *
 * 扫描指定目录下的 *.php 文件（每个文件返回一个数组），
 * 以“文件名”作为一级命名空间挂载到配置树。
 *
 * 例：config/app.php 返回 ['name' => 'kode']
 *     → 加载后可通过 config->get('app.name') 读取。
 */
namespace Kode\Core\Config;

final class Loader
{
    /**
     * @param string $path 配置目录绝对路径
     * @return array<string, mixed> 以文件名为键的配置树
     */
    public function load(string $path): array
    {
        $result = [];
        if (!is_dir($path)) {
            return $result;
        }

        $files = glob(rtrim($path, '/') . '/*.php') ?: [];
        foreach ($files as $file) {
            $name = basename($file, '.php');
            $data = require $file;
            if (is_array($data)) {
                $result[$name] = $data;
            }
        }

        return $result;
    }
}
