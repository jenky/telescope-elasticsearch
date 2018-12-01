<?php

namespace Jenky\TelescopeElasticsearch\Console;

use Illuminate\Console\Command;
use Jenky\TelescopeElasticsearch\Contracts\Installer;

class InstallCommand extends Command
{
    /**
     * Elasticsearch client.
     *
     * @var \Elasticsearch\Client
     */
    protected $elastic;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'elastic:telescope {--f|force : Force to create new index}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create Elasticsearch indices for Telescope';

    /**
     * Execute the console command.
     *
     * @param  \Jenky\TelescopeElasticsearch\Contracts\Installer $installer
     * @return void
     */
    public function handle(Installer $installer)
    {
        if ($this->option('force')) {
            $this->info('Delete all Telescope Elasticsearch indices!');
            $installer->uninstall();
        }

        $this->comment('Creating elastiseach indices...');

        $installer->install();

        $this->info('Telescope indices has been created successfully.');
    }
}
