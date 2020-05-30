<?php /** @noinspection PhpUndefinedClassInspection */

namespace CaponicaAmazonRainforest\Client;

use CaponicaAmazonRainforest\Entity\RainforestProduct;
use CaponicaAmazonRainforest\Entity\SiteAsin;
use CaponicaAmazonRainforest\Response\ProductResponse;
use CaponicaAmazonRainforest\Service\LoggerService;
use GuzzleHttp\Client;
use GuzzleHttp\Promise;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * Client used to interact with the underlying API
 *
 * @package CaponicaAmazonRainforest\Client
 */
class RainforestClient
{
    const AMAZON_SITE_AUSTRALIA     = 'amazon.com.au';
    const AMAZON_SITE_BRAZIL        = 'amazon.com.br';
    const AMAZON_SITE_CANADA        = 'amazon.ca';
    const AMAZON_SITE_FRANCE        = 'amazon.fr';
    const AMAZON_SITE_GERMANY       = 'amazon.de';
    const AMAZON_SITE_INDIA         = 'amazon.in';
    const AMAZON_SITE_ITALY         = 'amazon.it';
    const AMAZON_SITE_JAPAN         = 'amazon.co.jp';
    const AMAZON_SITE_MEXICO        = 'amazon.com.mx';
    const AMAZON_SITE_NETHERLANDS   = 'amazon.nl';
    const AMAZON_SITE_SPAIN         = 'amazon.es';
    const AMAZON_SITE_UAE           = 'amazon.ae';
    const AMAZON_SITE_UK            = 'amazon.co.uk';
    const AMAZON_SITE_USA           = 'amazon.com';

    /** @var LoggerInterface $logger */
    protected $logger;
    /** @var string $apiKey*/
    private $apiKey;

    /**
     * @param array $config             Must include "api_key" with value.
     * @param LoggerInterface $logger
     */
    public function __construct($config, LoggerInterface $logger = null) {
        $this->logger           = $logger;

        $this->apiKey           = $config['api_key'];
        if (empty($this->apiKey)) {
            throw new \InvalidArgumentException('Missing Rainforest API key');
        }
    }

    public function getValidAmazonSitesArray() {
        return [
            self::AMAZON_SITE_AUSTRALIA,
            self::AMAZON_SITE_BRAZIL,
            self::AMAZON_SITE_CANADA,
            self::AMAZON_SITE_FRANCE,
            self::AMAZON_SITE_GERMANY,
            self::AMAZON_SITE_INDIA,
            self::AMAZON_SITE_ITALY,
            self::AMAZON_SITE_JAPAN,
            self::AMAZON_SITE_MEXICO,
            self::AMAZON_SITE_NETHERLANDS,
            self::AMAZON_SITE_SPAIN,
            self::AMAZON_SITE_UAE,
            self::AMAZON_SITE_UK,
            self::AMAZON_SITE_USA,
        ];
    }
    public function isValidAmazonSite($siteString) {
        return in_array($siteString, $this->getValidAmazonSitesArray());
    }
    public function getValidAmazonSiteSuffixArray() {
        return [
            self::AMAZON_SITE_AUSTRALIA     => 'com.au',
            self::AMAZON_SITE_BRAZIL        => 'com.br',
            self::AMAZON_SITE_CANADA        => 'ca',
            self::AMAZON_SITE_FRANCE        => 'fr',
            self::AMAZON_SITE_GERMANY       => 'de',
            self::AMAZON_SITE_INDIA         => 'in',
            self::AMAZON_SITE_ITALY         => 'it',
            self::AMAZON_SITE_JAPAN         => 'co.jp',
            self::AMAZON_SITE_MEXICO        => 'com.mx',
            self::AMAZON_SITE_NETHERLANDS   => 'nl',
            self::AMAZON_SITE_SPAIN         => 'es',
            self::AMAZON_SITE_UAE           => 'ae',
            self::AMAZON_SITE_UK            => 'co.uk',
            self::AMAZON_SITE_USA           => 'com',
        ];
    }
    public function convertAmazonSiteToSuffix($amazonSite) {
        $suffixes = $this->getValidAmazonSiteSuffixArray();
        if (empty($suffixes[$amazonSite])) {
            throw new \InvalidArgumentException('Unknown Amazon Site: ' . $amazonSite);
        }
        return $suffixes[$amazonSite];
    }

    /**
     * Checks and de-duplicates an array of SiteAsins. Returns an array of SiteAsins indexed by getKey()
     *
     * @param SiteAsin[] $siteAsins     Array keys are ignored from the input array (and are not needed)
     * @return array
     */
    public function prepareSiteAsinArray($siteAsins) {
        if (!is_array($siteAsins)) {
            $siteAsins = [ $siteAsins ];
        }

        $cleanArray = [];
        foreach ($siteAsins as $siteAsin) {
            if (!$this->isValidAmazonSite($siteAsin->getSite())) {
                $this->logMessage("Removed SiteAsin with invalid Amazon site: $siteAsin", LoggerService::ERROR);
                continue;
            }

            $cleanArray[$siteAsin->getKey()] = $siteAsin;
        }

        return $cleanArray;
    }


    /**
     * @param SiteAsin|SiteAsin[] $siteAsins                        The SiteAsins to retrieve. Can also provide a single SiteAsin.
     * @param RainforestProduct|RainforestProduct[] $rfProducts     Array of RainforestProducts indexed by getKey(). If set then these objects
     *                                                              will be updated from the response, instead of creating new ones.
     * @return RainforestProduct|null
     * @throws \Exception
     */
    public function retrieveProductForSiteAsins($siteAsins, $rfProducts=null) {
        if (!is_array($siteAsins) && $siteAsins instanceof SiteAsin) {
            $siteAsins = [ $siteAsins->getKey() => $siteAsins ];
        }

        if (empty($rfProducts)) {
            $rfProducts = [];
        } elseif (!is_array($rfProducts) && $rfProducts instanceof RainforestProduct) {
            $rfProducts = [ $rfProducts->getKey() => $rfProducts ];
        }

        $rfProductResponses = $this->fetchProductDataForSiteAsins($siteAsins);

        foreach ($siteAsins as $siteAsin) {
            if (empty($rfProductResponses[$siteAsin->getKey()])) {
                continue;
            }

            if (empty($rfProducts[$siteAsin->getKey()])) {
                $rfProducts[$siteAsin->getKey()] = new RainforestProduct();
            }
            $rfProducts[$siteAsin->getKey()]->updateFromRainforestResponse($rfProductResponses[$siteAsin->getKey()]);
        }

        return $rfProducts;
    }
    /**
     * @param SiteAsin[] $siteAsins
     * @return ProductResponse[]
     * @throws \Exception
     */
    private function fetchProductDataForSiteAsins($siteAsins) {
        $client = new Client();
        $requests = [];
        $rfProductResponses = [];

        foreach ($siteAsins as $siteAsin) {
            $queryString = http_build_query([
                'type'          => 'product',
                'api_key'       => $this->apiKey,
                'amazon_domain' => $siteAsin->getSite(),
                'asin'          => $siteAsin->getAsin(),
            ]);

            $this->logMessage("Requesting product data from API for {$siteAsin->getKey()}", LoggerService::DEBUG);
            $requests[$siteAsin->getKey()] = $client->getAsync(sprintf('https://api.rainforestapi.com/request?%s', $queryString));
        }

        $responses = Promise\settle($requests)->wait();
        foreach ($responses as $key => $response) {
            try {
                $data = $this->validateResponseAndReturnData($response);
                $rfProductResponses[$key] = new ProductResponse($data);
            } catch (\Exception $e) {
                $this->logMessage("Could not extract Product data from response {$key}. Message: " . $e->getMessage(), LoggerService::ERROR);
            }
        }

        return $rfProductResponses;
    }

    /**
     * @param ResponseInterface $response
     * @return array
     * @throws \Exception
     */
    private function validateResponseAndReturnData($response) {
        if (is_array($response) && array_key_exists('state', $response)) {
            if (Promise\PromiseInterface::FULFILLED === $response['state']) {
                $response = $response['value'];
            } elseif (array_key_exists('reason', $response) && $response['reason'] instanceof \Exception) {
                throw $response['reason'];
            }
        }

        $responseCode = $response->getStatusCode();
        if ($responseCode !== 200) {
            throw new \Exception("Rainforest request failed. HTTP response was $responseCode. See https://rainforestapi.com/docs/response-codes for more details.");
        }

        $dataArray = json_decode($response->getBody(), true);

        foreach (ProductResponse::getMainKeys() as $key) {
            if (empty($dataArray[$key])) {
                $this->logMessage("Dump of data object returned from Rainforest:", LoggerService::DEBUG);
                $this->logMessage(print_r($dataArray, true), LoggerService::DEBUG);
                throw new \Exception("Rainforest response appears incomplete. It is missing field $key.");
            }
        }

        if (empty($dataArray[ProductResponse::MAIN_KEY_REQUEST_INFO]['success'])) {
            $this->logMessage("Dump of data object returned from Rainforest:", LoggerService::DEBUG);
            $this->logMessage(print_r($dataArray, true), LoggerService::DEBUG);
            throw new \Exception("Rainforest response does not contain 'request_info.success=true'.");
        }

        return $dataArray;
    }

    /**
     * Logs with an arbitrary level.
     *
     * @param mixed  $level
     * @param string $message
     * @param array  $context
     *
     * @return void
     */
    protected function logMessage($message, $level, $context = [])
    {
        if ($this->logger) {
            // Use the internal logger for logging.
            $this->logger->log($level, $message, $context);
        } else {
            echo $message;
        }
    }
}