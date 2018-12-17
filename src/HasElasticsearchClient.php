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
     * @return \Jenky\TelescopeElasticsearch\Storage\ElasticsearchResults
     */
    public function search(Search $search): ElasticsearchResults
    {
        return ElasticsearchResults::make(
            $this->elastic->search([
                'index' => 'telescope',
                'body' => $search->toArray(),
            ])
        );
    }
}
