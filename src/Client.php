<?php

declare(strict_types=1);

namespace Trexima\SriClient;

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

require __DIR__ . '/../vendor/autoload.php';

/**
 * Service for making requests on SRI API
 */
class Client
{
    private const
        CACHE_TTL = 86400, // In seconds (24 hours)
        DEFAULT_LANGUAGE = 'sk_SK';

    private const SCconvertorSRItoSustavaPovolani = [
        1 => 5,
        2 => 6,
        3 => 7,
        4 => 8,
        5 => 9,
        6 => 10,
        7 => 11,
        8 => 12,
        9 => 13,
        10 => 14,
        11 => 15,
        12 => 16,
        13 => 17,
        14 => 18,
        15 => 19,
        16 => 20,
        17 => 21,
        18 => 22,
        19 => 34,
        20 => 35,
        21 => 36,
        22 => 26,
        23 => 37,
        24 => 28,
        99 => 1
    ];

    /**
     * @var Client
     */
    private $client;

    /**
     * @var MethodParameterExtractor
     */
    private $methodParameterExtractor;

    /**
     * @var CacheInterface
     */
    private $cache;

    /**
     * Number of seconds for cache invalidation.
     *
     * @var int
     */
    private $cacheTtl;

    /**
     * @var string
     */
    private $language;

    /**
     * @var string
     */
    private $apiKey;

    private $conn;

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
     * @return mixed|ResponseInterface
     * @throws GuzzleException
     */
    public function get($resurce, $query = null): ResponseInterface
    {
        return $this->client->request('GET', $resurce, [
            'headers' => [
                'Accept' => 'application/json',
                'Content-type' => 'application/json',
                'Accept-language' => $this->language,
                'X-Api-Key' => $this->apiKey
            ],
            'query' => $query,
        ]);
    }

    /**
     * @param string $json
     * @param bool $assoc
     * @return mixed
     * @throws InvalidArgumentException if the JSON cannot be decoded.
     */
    public function jsonDecode(string $json, $assoc = true)
    {
        return \GuzzleHttp\json_decode($json, $assoc);
    }

    /**
     * Get NSZ by id
     *
     * @param string $id
     * @return mixed
     */
    public function createDbConnection(string $host, string $username, string $password, string $database, int $port)
    {
        $paramsDoctrine = [
            'driver' => 'pdo_mysql',
            'user' => $username,
            'password' => $password,
            'host' => $host,
            'dbname' => $database,
            'port' => $port,
            'charset' => 'utf8',
        ];
        $config = new \Doctrine\DBAL\Configuration();

        $this->conn = \Doctrine\DBAL\DriverManager::getConnection($paramsDoctrine, $config);
    }

    /**
     * Get NSZ by id
     *
     * @param string $id
     * @return mixed
     * @throws \Psr\Cache\InvalidArgumentException
     */
    public function getNszById(string $id)
    {
        $cacheKey = 'sc-' . md5($id);
        $result = $this->cache->get($cacheKey, function (ItemInterface $item) use ($id) {
            $item->expiresAfter($this->cacheTtl);
            $resource = $this->get(sprintf('/api/nszs/%d.json', $id));
            return (string)$resource->getBody();
        });

        return $this->jsonDecode($result);
    }

    public function getNszListBySC(int $id) {
        $scId = self::SCconvertorSRItoSustavaPovolani[$id];
        $sql = "SELECT sr.id_nsz, nsz.nazov, nsz.stav, nsz_ekr.ekr
                    FROM nsz_sr sr
                    JOIN nsz ON sr.id_nsz = nsz.id
                    LEFT JOIN nsz_ekr ON nsz_ekr.id_nsz = nsz.id
                    WHERE sr.id_sr = :sr AND nsz.zmazane = 0 AND nsz.stav NOT IN (7,10,11) AND nazov NOT LIKE '%revízia%'
                    ORDER BY nsz_ekr.ekr ASC, nsz.nazov ASC";
        $statement = $this->conn->prepare($sql);
        $statement->bindValue(":sr", $scId, "integer");
        $resultSet = $statement->executeQuery();
        $nszList = [];
        while (($row = $resultSet->fetchAssociative()) !== false) {
            $nszList[] = array(
                'id' => $row['id_nsz'],
                'nazov' => $row['nazov'],
                'stav' => $row['stav'],
                'ekr' => $row['ekr']
            );
        }

        return $nszList;
    }

}
