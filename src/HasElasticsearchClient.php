<?php

namespace Jenky\TelescopeElasticsearch;

use Cviebrock\LaravelElasticsearch\Facade;
use Jenky\TelescopeElasticsearch\Storage\ElasticsearchResults;
use ONGR\ElasticsearchDSL\Search;

trait HasElasticsearchClient
{
    /**
     * The elasticsearch client.
     *
     * @var \Elasticsearch\Client
     */
    protected $elastic;

    /**
     * Create a new migration instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->elastic = Facade::connection(
            config('telescope.storage.elasticsearch.connection')
        );
    }

    /**
     * Use Elasticsearch API to perform search.
     *
     * @param  \ONGR\ElasticsearchDSL\Search $search
     * @param  string $index
     * @return \Jenky\TelescopeElasticsearch\Storage\ElasticsearchResults
     */
    public function search(Search $search, $index = '.telescope'): ElasticsearchResults
    {
        return ElasticsearchResults::make(
            $this->elastic->search([
                'index' => $index,
                'body' => $search->toArray(),
            ])
        );
    }
}
