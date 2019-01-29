<?php

namespace Jenky\TelescopeElasticsearch;

use Illuminate\Support\ServiceProvider;
use Jenky\TelescopeElasticsearch\Storage\ElasticsearchEntriesRepository;
use Laravel\Telescope\Contracts\ClearableRepository;
use Laravel\Telescope\Contracts\EntriesRepository;
use Laravel\Telescope\Contracts\PrunableRepository;

class TelescopeElasticsearchServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        // $this->registerMigrations();
    }

    /**
     * Register any package services.
     *
     * @return void
     */
    public function register()
    {
        if (! $this->usingElasticsearchDriver()) {
            return;
        }

        // $this->app->when(Storage\ElasticsearchClient::class)
        //     ->needs('$connection')
        //     ->give($this->app['config']->get('telescope.storage.elasticsearch.connection'));

        $this->registerIntsaller();
        $this->registerStorageDriver();
    }

    /**
     * Register elasticsearch storage driver.
     *
     * @return void
     */
    protected function registerStorageDriver()
    {
        $this->app->singleton(EntriesRepository::class, ElasticsearchEntriesRepository::class);
        $this->app->singleton(ClearableRepository::class, ElasticsearchEntriesRepository::class);
        $this->app->singleton(PrunableRepository::class, ElasticsearchEntriesRepository::class);
    }

    /**
     * Register the installer.
     *
     * @return void
     */
    protected function registerIntsaller()
    {
        $this->app->singleton(Contracts\Installer::class, Storage\TelescopeInstaller::class);
    }

    /**
     * Determine if we should register the bindings.
     *
     * @return bool
     */
    protected function usingElasticsearchDriver()
    {
        return $this->app['config']->get('telescope.driver') == 'elasticsearch';
    }
}
