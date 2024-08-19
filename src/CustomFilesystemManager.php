<?php
/**
 * User: lonely walker
 * Date: 2024/8/19 10:21
 * Desc:
 */

namespace LonelyWalker\CustomLaravelStorage;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Support\Facades\Log;
use OSS\OssClient;

class CustomFilesystemManager extends FilesystemManager
{
    public function createOssDriver(array $config)
    {
        $accessId = $config['access_key'];
        $accessKey = $config['secret_key'];

        $cdnDomain = empty($config['cnd_domain']) ? '' : $config['cnd_domain'];
        $bucket = $config['bucket'];
        $ssl = empty($config['ssl']) ? false : $config['ssl'];
        $isCname = empty($config['is_cname']) ? false : $config['is_cname'];
        $debug = empty($config['debug']) ? false : $config['debug'];

        $endPoint = $config['endpoint']; // 默认作为外部节点
        $epInternal = $isCname ? $cdnDomain : (empty($config['endpoint_internal']) ? $endPoint : $config['endpoint_internal']); // 内部节点

        if ($debug) {
            Log::debug('OSS config:', $config);
        }

        $client = new OssClient($accessId, $accessKey, $epInternal, $isCname);
        $adapter = new CustomOssAdapter($client, $bucket, $endPoint, $ssl, $isCname, $debug, $cdnDomain);

        return new FilesystemAdapter($this->createFlysystem($adapter, $config), $adapter, $config);
    }

    public function createQiuNiuAdapter(array $config)
    {
        $adapter = new CustomQiNiuAdapter($config['access_key'], $config['secret_key'], $config['bucket'], $config['domain']);

        return new FilesystemAdapter($this->createFlysystem($adapter), $adapter, $config);
    }
}