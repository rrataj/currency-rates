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
    protected $cache_path = __DIR__.'/';

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
            $file_name = 'eurofxref-daily.xml';
        } else {
            $historical = true;
            $file_name = 'eurofxref-hist.xml';
        }
        $url = $this->url . '/' . $file_name;
        $query = [];

        // check if we have cached version and use it if it's not older than 1 hour
        if( file_exists( $cache_path . $file_name ) && filesize( $cache_path . $file_name ) && (time()-filemtime( $cache_path . $file_name ) < 1 * 3600) ) {
            $file = fopen( $cache_path . $file_name,'r' );
            $xml_response = fread( $file,filesize( $cache_path . $file_name ) );
            fclose($file);
        } else {
            // query the API
            $file = fopen( $cache_path . $file_name,'w' );
            try {
                $response = $this->guzzle->request('GET', $url);
                $xml_response = $response->getBody()->getContents();
                fwrite($file,$xml_response);
                fclose($file);
            } catch (TransferException $e) {
                throw new ConnectionException($e->getMessage());
            }
        }

        // process response
        $xml = simplexml_load_string($xml_response);
        $rates = array();

        if( $historical ) {
            // DATES Are sorted Newest first, so the logic is how it is
            foreach ($xml->Cube->Cube as $day) {
                $elements = $day;
                // extract date
                $day_string = (string) $day->attributes()['time'];
                // check for exact or next date
                if( strtotime( $day_string ) >= strtotime( $date ) ) {
                    // check if there is data for that day, if not - skip and use next, older day for which the data is available
                    if( empty($elements) ) {
                        continue;
                    }
                    // fetch currencies and put into array
                    foreach ($elements as $element) {
                        if( in_array( $element['currency'], $targets ) || empty( $targets ) ) {
                            $rates[ (string) $element['currency'] ] = (string)$element['rate'];
                        }
                    }
                }
            }
        } else {
            // extract actual date form response
             $date = (string) $xml->Cube->Cube->attributes()['time'];
            // fetch currencies and put into array
            foreach ($xml->Cube->Cube->Cube as $key => $element) {
                if( in_array( $element['currency'], $targets ) || empty( $targets ) ) {
                    $rates[ (string) $element['currency'] ] = (string)$element['rate'];
                }
            }
        }
        // check if everything is ok and return response
        if (is_array($rates) && isset($base) && isset($date)) {
           return new Result(
                $base,
                new DateTime($date),
                $rates
            );
        } elseif (isset($response['error'])) {
            throw new ResponseException($response['error']);
        } else {
            throw new ResponseException('Response has no currency exchange data.');
        }
    }
}
