# Banguat Exchange Rate PHP Class
Provides an easy-to-use class for communicating with the exchange rate web service of Banco de Guatemala.

```php
<?php

use Banguat\ExchangeRate;

$ExchangeRate = new ExchangeRate();

$amount = 100; // Amount in USD
$usd_rate = $ExchangeRate->getCurrencyExchangeRate('GTQ');

$total = $amount * $usd_rate->compra; // Amount in GTQ
```

## Requirements
PHP 5.6+. Other than that, this library has no external requirements.

## Installation
You can install this library via Composer.
```bash
$ composer require abdyfranco/banguat
```

## License
The MIT License (MIT). Please see "LICENSE.md" File for more information.