<?php

namespace Ultraleet\CurrencyRates\Providers;

use Ultraleet\CurrencyRates\AbstractProvider;
use Ultraleet\CurrencyRates\Result;
use Ultraleet\CurrencyRates\Exceptions\ConnectionException;
use Ultraleet\CurrencyRates\Exceptions\ResponseException;
use Ultraleet\CurrencyRates\Exceptions\BaseException;
use GuzzleHttp\Client as GuzzleClient;
use DateTime;
use InvalidArgumentException;
use GuzzleHttp\Exception\TransferException;

class EcbProvider extends AbstractProvider
{
    protected $guzzle;
    protected $base = 'EUR';
    protected $url_full = "https://www.ecb.europa.eu/stats/eurofxref/eurofxref-daily.xml";
    protected $url = "https://www.ecb.europa.eu/stats/eurofxref";

    /**
     * Class constructor.
     *
     * @param \GuzzleHttp\Client $guzzle
     * @return void
     */
    public function __construct(GuzzleClient $guzzle)
    {
        $this->guzzle = $guzzle;
    }

    /**
     * Get latest currency exchange rates.
     *
     * @param  string  $base
     * @param  array   $targets
     * @return \Ultraleet\CurrencyRates\Contracts\Result
     */
    public function latest($base = 'EUR', $targets = [])
    {
        return $this->query('eurofxref-daily.xml', $base, $targets);
    }

    /**
     * Get historical currency exchange rates.
     *
     * @param  \DateTime $date
     * @param  string    $base
     * @param  array     $targets
     * @return \Ultraleet\CurrencyRates\Contracts\Result
     */
    public function historical($date, $base = 'EUR', $targets = [])
    {
        return $this->query($date->format('Y-m-d'), $base, $targets);
    }

    /**
     * Set the base currency.
     *
     * @param string $currency
     * @return self
     */
    public function base($currency)
    {
        if( $currency != $this->base ) {
            throw new BaseException( '!! This provider does not support other base currency than EUR' );
        }
        return parent::base($currency);
    }

    /**
     * Get historical currency exchange rates.
     *
     * @param  string $date 'latest' for latest, 'Y-m-d' date for historical.
     * @param  string $base
     * @param  array  $targets
     * @return \Ultraleet\CurrencyRates\Contracts\Result
     */
    protected function query($date, $base, $targets)
    {
        $historical = false;
        if( $date == 'eurofxref-daily.xml' ) {
            $url = $this->url . '/' . $date;
        } else {
            $historical = true;
            $url = $this->url . '/' . 'eurofxref-hist.xml';
        }
        $query = [];

        // query the API
        try {
            $response = $this->guzzle->request('GET', $url);
        } catch (TransferException $e) {
            throw new ConnectionException($e->getMessage());
        }

        // process response
        $xml_response = $response->getBody()->getContents();
        $xml = simplexml_load_string($xml_response);
        $rates = array();

        if( $historical ) {
            foreach ($xml->Cube->Cube as $day) {
                // var_dump($day->Cube);exit;
                $elements = $day;
                $day_string = (string) $day->attributes()['time'];
                if( strtotime( $day_string ) == strtotime( $date ) ) {
                    foreach ($elements as $element) {
                        if( in_array( $element['currency'], $targets ) || empty( $targets ) ) {
                            $rates[ (string) $element['currency'] ] = (string)$element['rate'];
                        }
                    }
                }
            }
        } else {
             $date = (string) $xml->Cube->Cube->attributes()['time'];

            foreach ($xml->Cube->Cube->Cube as $key => $element) {
                if( in_array( $element['currency'], $targets ) || empty( $targets ) ) {
                    $rates[ (string) $element['currency'] ] = (string)$element['rate'];
                }
            }
        }
       

        return new Result(
                $base,
                new DateTime($date),
                $rates
            );

        exit;

        // @todo Add these checks later
        // if (isset($response['rates']) && is_array($response['rates']) &&
        //     isset($response['base']) && isset($response['date'])) {
        //     return new Result(
        //         $response['base'],
        //         new DateTime($response['date']),
        //         $response['rates']
        //     );
        // } elseif (isset($response['error'])) {
        //     throw new ResponseException($response['error']);
        // } else {
        //     throw new ResponseException('Response body is malformed.');
        // }
    }
}
