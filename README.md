A simple HTTP request library for PHP
=================

Features
-------------------

 * Sanitize (used [Rock Sanitize](https://github.com/romeOz/rock-sanitize))
 * Module for [Rock Framework](https://github.com/romeOz/rock)

Installation
-------------------

From the Command Line:

```composer require romeoz/rock-request:*```

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

// example url: http://site.com/foo/?page=1

// returns relative URL
(new Request)->getUrl(); // output: /foo/?page=1
```

####Sanitize

```php
use rock\sanitize\Sanitize;

// example url: http://site.com/foo/?page=<b>-1</b>

Request::get('page'); // output: -1

// Add custom sanitize
Request::get('page', null, Sanitize::removeTags()->trim()->positive()); // output: 1
```

Requirements
-------------------
 * **PHP 5.4+**

License
-------------------

HTTP request library is open-sourced software licensed under the [MIT license](http://opensource.org/licenses/MIT).