<?php

namespace Jenky\TelescopeElasticsearch;

use Cviebrock\LaravelElasticsearch\ServiceProvider as ElasticsearchServiceProvider;
use Illuminate\Support\ServiceProvider;

class TelescopeElasticsearchServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        if (!$this->app->bound(ElasticsearchServiceProvider::class)) {
            $this->app->register(ElasticsearchServiceProvider::class);
        }

        $this->registerElasticsearchDriver();
    }

    /**
     * Register elasticsearch storage driver.
     *
     * @return void
     */
    protected function registerElasticsearchDriver()
    {
        $this->app->singleton(EntriesRepository::class, ElasticsearchEntriesRepository::class);
    }
}
