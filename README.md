# telescope-elasticsearch

[![Latest Version on Packagist][ico-version]][link-packagist]
[![Software License][ico-license]](LICENSE.md)
[![Build Status][ico-travis]][link-travis]
[![Coverage Status][ico-scrutinizer]][link-scrutinizer]
[![Quality Score][ico-code-quality]][link-code-quality]
[![Total Downloads][ico-downloads]][link-downloads]

**Note:** Replace ```Lynh``` ```jenky``` ```https://github.com/jenky``` ```contact@lynh.me``` ```jenky``` ```telescope-elasticsearch``` ```Elastisearch driver for Laravel Telescope``` with their correct values in [README.md](README.md), [CHANGELOG.md](CHANGELOG.md), [CONTRIBUTING.md](CONTRIBUTING.md), [LICENSE.md](LICENSE.md) and [composer.json](composer.json) files, then delete this line. You can run `$ php prefill.php` in the command line to make all replacements at once. Delete the file prefill.php as well.

This is where your description should go. Try and limit it to a paragraph or two, and maybe throw in a mention of what
PSRs you support to avoid any confusion with users and contributors.


## Install

Via Composer

``` bash
composer require jenky/telescope-elasticsearch
```

The package will automatically register itself.

You can publish the migration with:

```
php artisan vendor:publish --provider="Jenky\TelescopeElasticsearch\TelescopeElasticsearchServiceProvider"
```

After you publish the configuration file as suggested above, you may configure ElasticSearch by adding the following to your application's .env file (with appropriate values):

``` ini
ELASTICSEARCH_HOST=localhost
ELASTICSEARCH_PORT=9200
ELASTICSEARCH_SCHEME=http
ELASTICSEARCH_USER=
ELASTICSEARCH_PASS=
```

Run the migration:

```
php artisan migrate
```

Then change your Telescope driver to:

``` ini
TELESCOPE_DRIVER=elasticsearch
```

## Change log

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Testing

``` bash
composer test
```

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) and [CODE_OF_CONDUCT](CODE_OF_CONDUCT.md) for details.

## Security

If you discover any security related issues, please email contact@lynh.me instead of using the issue tracker.

## Credits

- [Lynh][link-author]
- [All Contributors][link-contributors]

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.

[ico-version]: https://img.shields.io/packagist/v/jenky/telescope-elasticsearch.svg?style=flat-square
[ico-license]: https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square
[ico-travis]: https://img.shields.io/travis/jenky/telescope-elasticsearch/master.svg?style=flat-square
[ico-scrutinizer]: https://img.shields.io/scrutinizer/coverage/g/jenky/telescope-elasticsearch.svg?style=flat-square
[ico-code-quality]: https://img.shields.io/scrutinizer/g/jenky/telescope-elasticsearch.svg?style=flat-square
[ico-downloads]: https://img.shields.io/packagist/dt/jenky/telescope-elasticsearch.svg?style=flat-square

[link-packagist]: https://packagist.org/packages/jenky/telescope-elasticsearch
[link-travis]: https://travis-ci.org/jenky/telescope-elasticsearch
[link-scrutinizer]: https://scrutinizer-ci.com/g/jenky/telescope-elasticsearch/code-structure
[link-code-quality]: https://scrutinizer-ci.com/g/jenky/telescope-elasticsearch
[link-downloads]: https://packagist.org/packages/jenky/telescope-elasticsearch
[link-author]: https://github.com/jenky
[link-contributors]: ../../contributors
