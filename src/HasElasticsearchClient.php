<?php

namespace Jenky\TelescopeElasticsearch;

use Cviebrock\LaravelElasticsearch\Facade as Elasticsearch;

trait HasElasticsearchClient
{
    /**
     * Elasticsearch client.
     *
     * @var \Elasticsearch\Client
     */
    protected $elastic;

    /**
     * Inject elasticsearch client.
     *
     * @param  string|null $connection
     * @return void
     */
    public function __construct($connection = null)
    {
        $this->elastic = Elasticsearch::connection($connection);
    }
}
