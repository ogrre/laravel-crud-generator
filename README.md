# Laravel CRUD Generator

![Packagist Version](https://img.shields.io/packagist/v/ogrre/laravel-crud-generator)
![GitHub License](https://img.shields.io/github/license/0grre/laravel-crud-generator)
![Packagist Downloads](https://img.shields.io/packagist/dt/ogrre/laravel-crud-generator)

This package provides a simple way to generate CRUD (Create, Read, Update, Delete) operations for your Laravel application.

## Installation:

To install the Laravel CRUD Generator library, run the following command:

```shell
composer require ogrre/laravel-crud-generator
```

After the installation, publish the vendor files by executing the command:

```shell
php artisan vendor:publish --provider="Ogrre\\CrudGenerator\\CrudGeneratorServiceProvider"
```

By default, the service provider will be automatically registered in the `app.php` file. However, if needed, you can manually add the service provider in the `config/app.php` file:

```php
# config/app.php

'providers' => [
    // ...
    Ogrre\CrudGenerator\CrudGeneratorServiceProvider::class,
];
```

# Usage
After installing the package, you can use the command line to generate CRUD operations for a model:

```bash
php artisan make:crud NameOfYourModel
```

This command will create:

- A new Model (if it does not exist)
- A new Controller with CRUD methods
- Migration files for the database
- Requests validation files
- Update the routes file

## Customization
You can publish the configuration file and views to customize the generated files:

# Contributing
Contributions are welcome and will be fully credited. I accept contributions via Pull Requests on Github.

# Support me
<a href="https://www.buymeacoffee.com/0grre" target="_blank"><img src="https://cdn.buymeacoffee.com/buttons/v2/default-yellow.png" alt="Buy Me A Coffee" style="height: 60px !important;width: 217px !important;" ></a>

