<?php
/**
 * Provides an easy-to-use class for communicating with the exchange rate
 * web service of Banco de Guatemala.
 *
 * @package Banguat
 * @subpackage Banguat.ExchangeRate
 * @copyright Copyright (c) 2018-2019 Abdy Franco. All Rights Reserved.
 * @license https://opensource.org/licenses/MIT The MIT License (MIT)
 * @author Abdy Franco <iam@abdyfran.co>
 */

namespace Banguat;

use SoapFault;
use SoapClient;

class ExchangeRate
{
    private $endpoint = 'http://www.banguat.gob.gt/variables/ws/TipoCambio.asmx?wsdl';

    private $codes = [
        1  => 'GTQ',
        2  => 'USD',
        3  => 'JPY',
        4  => 'CHF',
        7  => 'CAD',
        9  => 'GBP',
        15 => 'SEK',
        16 => 'CRC',
        18 => 'MXN',
        19 => 'HNL',
        21 => 'NIO',
        23 => 'DKK',
        24 => 'EUR',
        25 => 'NOK',
        29 => 'ARS',
        30 => 'BRL',
        31 => 'KRW',
        32 => 'HKD',
        33 => 'TWD',
        34 => 'CNY',
        35 => 'PKR',
        36 => 'INR',
        38 => 'COP',
        39 => 'DOP',
        40 => 'MYR',
        41 => 'VES',
        42 => 'PLN'
    ];

    public function __construct()
    {
        // Set the Guatemalan time zone
        date_default_timezone_set('America/Guatemala');
    }

    private function request($function, $params = [])
    {
        $options = [
            'trace'          => 1,
            'exceptions'     => true,
            'cache_wsdl'     => WSDL_CACHE_NONE,
            'stream_context' => stream_context_create([
                'ssl' => [
                    'verify_peer'       => false,
                    'verify_peer_name'  => false,
                    'allow_self_signed' => true
                ]
            ])
        ];

        try {
            $api = new SoapClient($this->endpoint, $options);

            $response = $api->__soapCall($function, ['parameters' => $params]);
        } catch (SoapFault $fault) {
            throw new \Exception($fault->faultstring);
        }

        return $response;
    }

    public function todayExchangeRate()
    {
        $result = $this->request('TipoCambioDia');

        if (!empty($result->TipoCambioDiaResult->CambioDolar->VarDolar)) {
            return $result->TipoCambioDiaResult->CambioDolar->VarDolar;
        }

        return (object) [];
    }

    public function getAvailableCurrencies()
    {
        $result = $this->request('VariablesDisponibles');

        if (!empty($result->VariablesDisponiblesResult->Variables->Variable)) {
            foreach ($result->VariablesDisponiblesResult->Variables->Variable as $key => $value) {
                $result->VariablesDisponiblesResult->Variables->Variable[$key]->codigo = isset($this->codes[$value->moneda]) ? $this->codes[$value->moneda] : null;
            }

            return $result->VariablesDisponiblesResult->Variables->Variable;
        }

        return [];
    }

    public function getRangeExchangeRate($initial_date = null, $ending_date = null, $currency = null)
    {
        if (is_null($currency)) {
            $params = [
                'fechainit' => (!is_null($initial_date)) ? $initial_date : date('d/m/Y'),
                'fechafin'  => (!is_null($ending_date)) ? $ending_date : date('d/m/Y')
            ];

            $result = $this->request('TipoCambioRango', $params);

            if (!empty($result->TipoCambioRangoResult->Vars->Var)) {
                return $result->TipoCambioRangoResult->Vars->Var;
            }
        } else {
            $params = [
                'fechainit' => (!is_null($initial_date)) ? $initial_date : date('d/m/Y'),
                'fechafin'  => (!is_null($ending_date)) ? $ending_date : date('d/m/Y'),
                'moneda'    => (int) $currency
            ];

            $result = $this->request('TipoCambioRangoMoneda', $params);

            if (!empty($result->TipoCambioRangoMonedaResult->Vars->Var)) {
                return $result->TipoCambioRangoMonedaResult->Vars->Var;
            }
        }

        // Maybe there is not exchange rate for today, we will try to use the same exchange rate as yesterday
        if (($initial_date == $ending_date) && $initial_date == date('d/m/Y')) {
            $date = date('d/m/Y', strtotime('-1 day'));

            return $this->getRangeExchangeRate($date, $date, $currency);
        }

        return (object) [];
    }

    public function getCurrencyExchangeRate($currency = null)
    {
        // Replace the GTQ exchange rate with the USD exchange rate
        $gtq_primary = false;

        if ($currency == 'GTQ' || $currency == 1) {
            $currency    = 'USD';
            $gtq_primary = true;
        }

        // Get the exchange rate of the provided currency
        $date = date('d/m/Y');

        if (is_numeric($currency)) {
            $result = $this->getRangeExchangeRate($date, $date, $currency);
        } elseif (is_string($currency)) {
            $currencies = $this->getAvailableCurrencies();

            foreach ($currencies as $value) {
                if ($value->codigo == $currency) {
                    $currency = $value->moneda;
                    $result   = $this->getRangeExchangeRate($date, $date, $currency);
                }
            }
        }

        // Format USD based exchange rates
        $usd_based = [
            24, // EUR
            26, // DEG
            9 // GBP
        ];

        if (isset($result->moneda) && in_array($result->moneda, $usd_based)) {
            $result->venta  = round(1 / $result->venta, 5);
            $result->compra = round(1 / $result->compra, 5);
        }

        // Format GTQ exchange rate
        if ($gtq_primary) {
            $result->moneda = 1;
        }

        // Format USD exchange rate
        if (($currency == 'USD' || $currency == 2) && !$gtq_primary) {
            return (object) [
                'moneda' => $currency,
                'fecha'  => date('d/m/Y'),
                'venta'  => 1,
                'compra' => 1
            ];
        }

        return $result;
    }

    public function convertCurrency($amount, $from = 'GTQ', $to = 'USD')
    {
        if ($from == $to) {
            return $amount;
        }

        $from = $this->getCurrencyExchangeRate($from);
        $to   = $this->getCurrencyExchangeRate($to);

        // Convert the origin to USD
        $amount = round($amount / $from->compra, 5);

        // Convert from USD to destination
        $amount = round($amount * $to->compra, 5);

        return $amount;
    }
}
