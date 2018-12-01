<?php

namespace Jenky\TelescopeElasticsearch\Console;

use Illuminate\Console\Command;
use Jenky\TelescopeElasticsearch\Contracts\Installer;

class UninstallCommand extends Command
{
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
    protected $description = 'Delete Telescope Elasticsearch indices';

    /**
     * Execute the console command.
     *
     * @return void
     */
    public function handle(Installer $installer)
    {
        $installer->uninstall();

        $this->info('Deleted all elasticsearch indices for Telescope');
    }
}
