A simple HTTP request library for PHP
=================

[![Latest Stable Version](https://poser.pugx.org/romeOz/rock-request/v/stable.svg)](https://packagist.org/packages/romeOz/rock-request)
[![Total Downloads](https://poser.pugx.org/romeOz/rock-request/downloads.svg)](https://packagist.org/packages/romeOz/rock-request)
[![Build Status](https://travis-ci.org/romeOz/rock-request.svg?branch=master)](https://travis-ci.org/romeOz/rock-request)
[![HHVM Status](http://hhvm.h4cc.de/badge/romeoz/rock-request.svg)](http://hhvm.h4cc.de/package/romeoz/rock-request)
[![Coverage Status](https://coveralls.io/repos/romeOz/rock-request/badge.svg?branch=master)](https://coveralls.io/r/romeOz/rock-request?branch=master)
[![License](https://poser.pugx.org/romeOz/rock-request/license.svg)](https://packagist.org/packages/romeOz/rock-request)

Features
-------------------

 * Sanitizing http-variables
 * Standalone module/component for [Rock Framework](https://github.com/romeOz/rock)

Installation
-------------------

From the Command Line:

```
composer require romeoz/rock-request
```

In your composer.json:

```json
{
    "require": {
        "romeoz/rock-request": "*"
    }
}
```

Quick Start
-------------------

```php
use rock\request\Request;

// example url: http://site.com/foo/?page=1&foo[bar]=test

// returns relative URL
(new Request)->getUrl(); // output: /foo/?page=1&foo[bar]=test

// returns host
(new Request)->getHost(); // output: site.com

// returns query params
(new Request)->rawGet(); // output: ['page' => 1, 'foo' => ['bar' => 'test']]

// returns query params as multiple
(new Request)->rawGet(['foo', 'bar']); // output: test

// alternative approach
(new Request)->rawGet('foo.bar'); // output: test
```

####Sanitize

For sanitizing http-variables required install [Rock Sanitize](https://github.com/romeOz/rock-sanitize): `composer require romeoz/rock-sanitize`.
 
```php
use rock\sanitize\Sanitize;

// example url: http://site.com/foo/?page=<b>-1</b>

// default sanitize: Sanitize::removeTags()->trim()->toType(); 
Request::get('page'); // output: -1

// Add custom sanitize
Request::get('page', null, Sanitize::removeTags()->trim()->positive()); // output: 1
```

Requirements
-------------------
 * **PHP 5.4+**
 * For sanitizing http-variables required [Rock Sanitize](https://github.com/romeOz/rock-sanitize): `composer require romeoz/rock-sanitize`

>All unbolded dependencies is optional

License
-------------------

HTTP request library is open-sourced software licensed under the [MIT license](http://opensource.org/licenses/MIT).