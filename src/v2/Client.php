<?php

declare(strict_types=1);

namespace Trexima\SriClient\v2;

use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\InvalidArgumentException;
use GuzzleHttp\Utils;
use Psr\Http\Message\ResponseInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use GuzzleHttp\Client as GuzzleHttpClient;
use Trexima\SriClient\MethodParameterExtractor;

/**
 * Service for making requests on SRI API.
 */
class Client
{
    private const
        CACHE_TTL = 900, // In seconds (15 min)
        DEFAULT_LANGUAGE = 'sk_SK';

    /**
     * @var GuzzleHttpClient
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

    public function __construct(
        string                   $apiUrl,
        string                   $apiKey,
        MethodParameterExtractor $methodParameterExtractor,
        CacheInterface           $cache,
        int                      $cacheTtl = self::CACHE_TTL,
        string                   $language = self::DEFAULT_LANGUAGE
    )
    {
        $this->client = new GuzzleHttpClient(['base_uri' => rtrim($apiUrl, '/') . '/']);
        $this->methodParameterExtractor = $methodParameterExtractor;
        $this->cache = $cache;
        $this->cacheTtl = $cacheTtl;
        $this->language = $language;
        $this->apiKey = $apiKey;
    }

    /**
     * Perform request on API.
     *
     * @param $resurce
     * @param null $query
     * @param null $body
     * @param string $method
     * @return mixed|ResponseInterface
     * @throws GuzzleException
     */
    public function makeRequest($resurce, $query = null, $body = null, string $method = 'GET'): ResponseInterface
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
     * Perform GraphQL request on API.
     * 
     * @param string $query
     * @throws GuzzleException
     */
    public function getGraphQL(string $query)
    {
        $resource = $this->makeRequest('/api/graphql', null, $query, 'POST');
        return $this->jsonDecode($resource->getBody()->getContents());
    }

    /**
     * @param string $json
     * @param bool $assoc
     * @return mixed
     * @throws InvalidArgumentException if the JSON cannot be decoded.
     */
    public function jsonDecode(string $json, bool $assoc = true)
    {
        return Utils::jsonDecode($json, $assoc);
    }

    /**
     * Search in organizations.
     *
     * @param string $title
     * @param int $type
     * @param int $group
     * @return mixed
     * @throws \Psr\Cache\InvalidArgumentException
     */
    public function searchOrganization(int $type, int $group, string $title = null)
    {
        $parameterNames = array_slice($this->methodParameterExtractor->extract(__CLASS__, __FUNCTION__), 0, func_num_args());
        $args = array_filter(array_combine($parameterNames, func_get_args()));

        if (!empty($args['type'])) $args['type.id'] = $type;
        if (!empty($args['group'])) $args['group.id'] = $group;

        unset($args['type']);
        unset($args['group']);

        $cacheKey = 'search-organization-' . crc32(json_encode($args));
        $result = $this->cache->get($cacheKey, function (ItemInterface $item) use ($args) {
            $item->expiresAfter($this->cacheTtl);
            $resource = $this->makeRequest('api/strategy_organizations', $args);

            return (string)$resource->getBody();
        });

        return $this->jsonDecode($result);
    }

    /**
     * Get organization by CRN.
     *
     * @param string $ico
     * @return mixed
     * @throws \Psr\Cache\InvalidArgumentException
     */
    public function getOrganizationByCrn(string $crn)
    {
        if (empty($crn)) return null;
        $args = ['ico' => $crn];

        $cacheKey = 'search-organization-' . crc32(json_encode($args));
        $result = $this->cache->get($cacheKey, function (ItemInterface $item) use ($args) {
            $item->expiresAfter($this->cacheTtl);
            $resource = $this->makeRequest('api/strategy_organizations', $args);

            return (string)$resource->getBody();
        });

        $organizations = $this->jsonDecode($result);
        return $organizations[0] ?? null;
    }

    /**
     * Get organization by id.
     *
     * @param int $id
     * @return mixed
     * @throws \Psr\Cache\InvalidArgumentException
     */
    public function getOrganizationById(int $id)
    {
        $cacheKey = 'get-organization-' . crc32(strval($id));
        $result = $this->cache->get($cacheKey, function (ItemInterface $item) use ($id) {
            $item->expiresAfter($this->cacheTtl);
            $resource = $this->makeRequest('api/strategy_organizations/' . $id);

            return (string)$resource->getBody();
        });

        return $this->jsonDecode($result);
    }

    /**
     * Get strategy organization categories.
     *
     * @param int $level
     * @param int $parent
     * @return mixed
     * @throws \Psr\Cache\InvalidArgumentException
     */
    public function getStrategyOrganizationCategories(int $level = null, int $parent = null)
    {
        $args = [];
        if (isset($parent)) $args['parent.id'] = $parent;
        if (isset($level)) $args['level'] = $level;

        $cacheKey = 'search-organization-' . crc32(json_encode($args));
        $result = $this->cache->get($cacheKey, function (ItemInterface $item) use ($args) {
            $item->expiresAfter($this->cacheTtl);
            $resource = $this->makeRequest('api/strategy_organization_categories', $args);

            return (string)$resource->getBody();
        });

        return $this->jsonDecode($result);
    }

    /**
     * Get organization activities grupped by focuses.
     *
     * @param int $organization
     * @return mixed
     * @throws \Psr\Cache\InvalidArgumentException
     */
    public function getOrganizationActivitiesByFocus(int $organization)
    {
        $parameterNames = array_slice($this->methodParameterExtractor->extract(__CLASS__, __FUNCTION__), 0, func_num_args());
        $args = array_filter(array_combine($parameterNames, func_get_args()));

        $cacheKey = 'organization-activities-by-focus-' . crc32(json_encode($args));
        $result = $this->cache->get($cacheKey, function (ItemInterface $item) use ($args) {
            $item->expiresAfter($this->cacheTtl);
            $resource = $this->makeRequest('api/organization_activities_by_focuses', $args);

            return (string)$resource->getBody();
        });

        return $this->jsonDecode($result);
    }

    /**
     * Get organization activities grupped by years.
     *
     * @param int $organization
     * @return mixed
     * @throws \Psr\Cache\InvalidArgumentException
     */
    public function getOrganizationActivitiesByYear(int $organization)
    {
        $parameterNames = array_slice($this->methodParameterExtractor->extract(__CLASS__, __FUNCTION__), 0, func_num_args());
        $args = array_filter(array_combine($parameterNames, func_get_args()));

        $cacheKey = 'organization-activities-by-year-' . crc32(json_encode($args));
        $result = $this->cache->get($cacheKey, function (ItemInterface $item) use ($args) {
            $item->expiresAfter($this->cacheTtl);
            $resource = $this->makeRequest('api/organization_activities_by_years', $args);

            return (string)$resource->getBody();
        });

        return $this->jsonDecode($result);
    }

    /**
     * Get organization activities grupped by sector councils.
     *
     * @param int $organization
     * @return mixed
     * @throws \Psr\Cache\InvalidArgumentException
     */
    public function getOrganizationActivitiesBySectorCouncils(int $organization)
    {
        $parameterNames = array_slice($this->methodParameterExtractor->extract(__CLASS__, __FUNCTION__), 0, func_num_args());
        $args = array_filter(array_combine($parameterNames, func_get_args()));

        $cacheKey = 'organization-activities-by-sector-council-' . crc32(json_encode($args));
        $result = $this->cache->get($cacheKey, function (ItemInterface $item) use ($args) {
            $item->expiresAfter($this->cacheTtl);
            $resource = $this->makeRequest('api/organization_activities_by_sector_councils', $args);

            return (string)$resource->getBody();
        });

        return $this->jsonDecode($result);
    }
}
