<?php

declare(strict_types=1);

namespace Trexima\SriClient;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use GuzzleHttp\BodySummarizer;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\InvalidArgumentException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Utils;
use Psr\Http\Message\ResponseInterface;
use ReflectionException;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Service for making requests on SRI API
 */
class Client
{
    private const
        CACHE_TTL = 86400, // In seconds (24 hours)
        DEFAULT_LANGUAGE = 'sk_SK';

    /**
     * @var Client
     */
    private $client;

    /**
     * @var MethodParameterExtractor
     */
    private MethodParameterExtractor $methodParameterExtractor;

    /**
     * @var CacheInterface
     */
    private CacheInterface $cache;

    /**
     * Number of seconds for cache invalidation.
     *
     * @var int
     */
    private int $cacheTtl;

    /**
     * @var string
     */
    private string $language;

    /**
     * @var string
     */
    private string $apiKey;

    /**
     * @var Connection
     */
    private Connection $conn;

    public function __construct(
        string                   $apiUrl,
        string                   $apiKey,
        MethodParameterExtractor $methodParameterExtractor,
        CacheInterface           $cache,
        int                      $cacheTtl = self::CACHE_TTL,
        string                   $language = self::DEFAULT_LANGUAGE
    )
    {
        $this->client = new \GuzzleHttp\Client(['base_uri' => rtrim($apiUrl, '/') . '/']);

        $this->methodParameterExtractor = $methodParameterExtractor;
        $this->cache = $cache;
        $this->cacheTtl = $cacheTtl;
        $this->language = $language;
        $this->apiKey = $apiKey;
    }

    /**
     * Perform get request on API
     *
     * @param $resurce
     * @param null $query
     * @param null $body
     * @param string $method
     * @return mixed|ResponseInterface
     * @throws GuzzleException
     */
    public function get($resurce, $query = null, $body = null, string $method = 'GET'): ResponseInterface
    {
        return $this->client->request($method, $resurce, [
            'headers' => [
                'Accept' => 'application/json',
                'Content-type' => 'application/json',
                'Accept-language' => $this->language,
                'X-Api-Key' => $this->apiKey
            ],
            'query' => $query,
            'body' => $body,
        ]);
    }

    /**
     * @param string $json
     * @param bool $assoc
     * @return mixed
     * @throws InvalidArgumentException if the JSON cannot be decoded.
     */
    public function jsonDecode(string $json, bool $assoc = true)
    {
        return \GuzzleHttp\json_decode($json, $assoc);
    }

    public function getUrl(string $url)
    {
        $resource = $this->get($url);
        return $this->jsonDecode($resource->getBody()->getContents());
    }

    /**
     * @throws GuzzleException
     */
    public function getGraphQl($query)
    {
        $resource = $this->get('/api/graphql', null, $query, 'POST');
        return $this->jsonDecode($resource->getBody()->getContents());
    }

    /**
     * Get NSZ by id
     *
     * @param int $id
     * @return mixed
     * @throws \Psr\Cache\InvalidArgumentException
     */
    public function getNszById(int $id)
    {
        $cacheKey = 'nsz-by-id-' . md5((string)$id);
        $result = $this->cache->get($cacheKey, function (ItemInterface $item) use ($id) {
            $item->expiresAfter($this->cacheTtl);
            $resource = $this->get(sprintf('/api/nszs/%d.json', $id));
            return (string)$resource->getBody();
        });

        return $this->jsonDecode($result);
    }

    public function getNszList(array $queryParams = [], array $bodyParams = []): ?array
    {
        $cacheKey = 'nsz-list-' . md5(json_encode($queryParams + $bodyParams));
        $result = $this->cache->get($cacheKey, function (ItemInterface $item) use ($queryParams, $bodyParams) {
            $item->expiresAfter($this->cacheTtl);
            $resource = $this->get('/api/nszs.json', array_merge(['pagination' => false], $queryParams), $bodyParams ? json_encode($bodyParams) : null);
            return (string)$resource->getBody();
        });

        return $this->jsonDecode($result);
    }

    public function getNszIscos(string $field = null)
    {
        $cacheKey = 'nsz-iscos-' . md5((string)$field);

        return $this->cache->get($cacheKey, function (ItemInterface $item) use ($field) {
            $item->expiresAfter($this->cacheTtl);
            $resource = $this->get('/api/nsz_iscos.json', ['pagination' => false]);

            $result = $this->jsonDecode((string)$resource->getBody());

            if ($field) {
                $array = [];

                foreach ($result as $item) {
                    if (isset($item[$field])) {
                        $array[] = $item[$field];
                    }
                }

                $result = $array;
            }

            return $result;
        });
    }
}
