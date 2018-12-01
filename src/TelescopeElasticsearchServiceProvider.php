<?php

namespace Jenky\TelescopeElasticsearch;

use Illuminate\Support\ServiceProvider;
use Cviebrock\LaravelElasticsearch\Manager;
use Cviebrock\LaravelElasticsearch\ServiceProvider as ElasticsearchServiceProvider;

class TelescopeElasticsearchServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        //
    }

    /**
     * Register any package services.
     *
     * @return void
     */
    public function register()
    {
        if (!$this->shouldRegister()) {
            return;
        }

        if (!$this->app->bound(ElasticsearchServiceProvider::class)) {
            $this->app->register(ElasticsearchServiceProvider::class);
        }

        $this->app->when(Contracts\ElasticsearchClient::class)
            ->needs('$connection')
            ->give($this->app['config']->get('telescope.storage.elasticsearch.connection'));

        $this->registerInstaller();
        $this->registerStorageDriver();
    }

    protected function registerInstaller()
    {
        $this->app->singleton(Contracts\Installer::class, Console\CreateTelescopeIndices::class);

        $this->commands([
            Console\InstallCommand::class,
        ]);
    }

    /**
     * Register elasticsearch storage driver.
     *
     * @return void
     */
    protected function registerStorageDriver()
    {
        $this->app->singleton(EntriesRepository::class, ElasticsearchEntriesRepository::class);
    }

    /**
     * Determine if we should register the bindings.
     *
     * @return bool
     */
    protected function shouldRegister()
    {
        return $this->app['config']->get('telescope.driver') == 'elasticsearch';
        // return true;
    }
}
