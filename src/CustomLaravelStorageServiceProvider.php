<?php
/**
 * User: lonely walker
 * Date: 2024/8/19 10:24
 * Desc:
 */

namespace LonelyWalker\CustomLaravelStorage;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;

class CustomLaravelStorageServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the application services.
     *
     * @return void
     */
    public function boot()
    {
        Storage::extend('custom-oss', function ($app, $config) {
            return (new CustomFilesystemManager($app))->createOssDriver($config);
        });
        Storage::extend('custom-qiniu', function ($app, $config) {
            return (new CustomFilesystemManager($app))->createQiuNiuAdapter($config);
        });
    }

    /**
     * Register the application services.
     *
     * @return void
     */
    public function register() {}
}