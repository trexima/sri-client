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
use Cocur\Slugify\Slugify;

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

    public function getWorkAreasWithNszCount() {
        $sql = "SELECT count(id_nsz) as pocet, kod_oblasti, nazov
                FROM
                    (SELECT DISTINCT p_skisco08_pracovna_oblast.id_prac_oblast AS kod_oblasti,
                        c_skisco08_oblasti.nazov, nsz.id AS id_nsz
                    FROM p_skisco08_pracovna_oblast
                    JOIN nsz_skisco08 ON nsz_skisco08.skisco08=p_skisco08_pracovna_oblast.skisco08
                    JOIN nsz ON nsz.id=nsz_skisco08.id_nsz
                    JOIN nsz_ekr ON nsz_ekr.id_nsz = nsz.id
                    JOIN c_skisco08_oblasti ON c_skisco08_oblasti.kod = p_skisco08_pracovna_oblast.id_prac_oblast
                    WHERE nsz.zmazane =0 AND nsz.stav = 6 
                    ) AS t
                GROUP BY kod_oblasti 
                ORDER BY nazov";
        $statement = $this->conn->prepare($sql);
        $resultSet = $statement->executeQuery();
        $nszList = [];
        while (($row = $resultSet->fetchAssociative()) !== false) {
            $nszList[] = array(
                'kod' => $row['kod_oblasti'],
                'nazov' => $row['nazov'],
                'pocet' => $row['pocet']
            );
        }

        return $nszList;
    }

    public function getWorkAreasNameByIds(array $ids)
    {
        $idsInt = [];
        foreach ($ids as $item) {
            $idsInt[] = (int)$item;
        }

        $sql = "SELECT kod AS id, nazov
                FROM c_skisco08_oblasti
                WHERE kod IN (:codes)
                ORDER BY nazov ASC";
        $statement = $this->conn->prepare($sql);
        $statement->bindValue(":codes", implode(",", $idsInt));

        $resultSet = $statement->executeQuery();
        $workAreasList = $resultSet->fetchAllAssociative();

        return $workAreasList;
    }

    public function getPublishedNSZByWorkArea(int $id)
    {
        $sql = "SELECT nsz.id AS id_nsz, nsz.nazov, nsz_ekr.ekr
                FROM p_skisco08_pracovna_oblast
                JOIN nsz_skisco08 ON nsz_skisco08.skisco08=p_skisco08_pracovna_oblast.skisco08
                JOIN nsz ON nsz.id=nsz_skisco08.id_nsz
                JOIN nsz_ekr ON nsz_ekr.id_nsz = nsz.id
                WHERE id_prac_oblast= :workarea AND nsz.zmazane = 0 AND nsz.stav = 6
                GROUP BY nsz.id      
                ORDER BY nsz_ekr.ekr ASC, nsz.nazov ASC";
        $statement = $this->conn->prepare($sql);
        $statement->bindValue(":workarea", $id, "integer");

        $resultSet = $statement->executeQuery();

        $nszList = [];
        while (($row = $resultSet->fetchAssociative()) !== false) {
            $nszList[] = array(
                'id' => $row['id_nsz'],
                'nazov' => $row['nazov'],
                'ekr' => $row['ekr']
            );
        }

        return $nszList;
    }

    public function getWorkAreasIdFromSlug(string $slug)
    {
        $slugify = new Slugify();

        $workAreas = $this->getWorkAreas();

        $workAreaId = null;
        foreach ($workAreas as $workArea)
        {
            $workAreaSlug = $slugify->slugify($workArea['nazov']);
            if ($workAreaSlug == $slug) {
                $workAreaId = (int)$workArea['kod'];
            }
        }

        return $workAreaId;
    }

    public function getWorkAreas()
    {
        $sql = "SELECT kod, nazov
                FROM c_skisco08_oblasti
                ORDER BY nazov ASC";
        $statement = $this->conn->prepare($sql);

        $resultSet = $statement->executeQuery();
        $workAreas = $resultSet->fetchAllAssociative();

        return $workAreas;
    }

    public function getGeneralCompetences()
    {
        $sql = "SELECT id, nazov, popis
                FROM c_vseobecne_sposobilosti
                WHERE (id<=26 OR id>2502) AND id!=22 AND (id>=2300 OR id<2200)
                ORDER BY poradie, nazov ASC";
        $statement = $this->conn->prepare($sql);

        $resultSet = $statement->executeQuery();
        $generalCompetences = $resultSet->fetchAllAssociative();

        return $generalCompetences;
    }

    public function getEducationDegrees()
    {
        $sql = "SELECT id, nazov
                FROM c_psv
                ORDER BY poradie ASC";
        $statement = $this->conn->prepare($sql);

        $resultSet = $statement->executeQuery();
        $educationDegrees = $resultSet->fetchAllAssociative();

        return $educationDegrees;
    }

    public function getPublishedNSZByCompetence(string $fulltext, array $gc, array $ed, array $wa)
    {
        $sqlCondition="";
        if (!empty($gc)) {
            $sqlCondition .= " AND nsz_vseobecne_sposobilosti.vseobecne_sposobilosti IN (".implode(",", $gc).") " ;
        }
        if (!empty($ed)) {
            $sqlCondition .= " AND nsz_psv.psv IN (".implode(",", $ed).") ";
        }
        if (!empty($wa)) {
            $sqlCondition .= " AND p_skisco08_pracovna_oblast.id_prac_oblast IN (".implode(",", $wa).") ";
        }
        $fulltextCondition = false;
        $fulltext = trim($fulltext);
        if (!empty($fulltext)) {
            $fulltextCondition = true;
            $sqlCondition .= " AND (nsz_odborne_vedomosti.odb_vedomost_text LIKE :text OR nsz_odborne_zrucnosti.odb_zrucnost_text LIKE :text) ";
        }

        $sql = "SELECT 
                    DISTINCT nsz.id AS id,
                    nsz.nazov,
                    nsz_ekr.ekr,
                    c_skisco08_oblasti.kod AS kod_oblasti,
                    c_skisco08_oblasti.nazov as nazov_oblasti 
                FROM nsz
                JOIN nsz_ekr ON nsz_ekr.id_nsz=nsz.id
                JOIN nsz_vseobecne_sposobilosti ON nsz_vseobecne_sposobilosti.id_nsz=nsz.id
                JOIN nsz_psv ON nsz_psv.id_nsz=nsz.id
                JOIN nsz_skisco08 ON nsz_skisco08.id_nsz=nsz.id
                JOIN p_skisco08_pracovna_oblast ON p_skisco08_pracovna_oblast.skisco08=nsz_skisco08.skisco08
                JOIN c_skisco08_oblasti ON c_skisco08_oblasti.kod=p_skisco08_pracovna_oblast.id_prac_oblast
                ".(
                    $fulltextCondition ?
                    "JOIN nsz_odborne_vedomosti ON nsz_odborne_vedomosti.id_nsz=nsz.id
                    JOIN nsz_odborne_zrucnosti ON nsz_odborne_zrucnosti.id_nsz=nsz.id "
                    : ""
                )."
                WHERE nsz.zmazane=0 AND nsz.stav=6 ".$sqlCondition."
                ORDER BY c_skisco08_oblasti.nazov ASC, nsz_ekr.ekr ASC, nazov ASC";

        $statement = $this->conn->prepare($sql);
        if ($fulltextCondition) {
            $statement->bindValue(":text", "%%".$fulltext."%%");
        }

        $resultSet = $statement->executeQuery();
        $nszList = $resultSet->fetchAllAssociative();

        return $nszList;
    }

    public function getWorkAreaByCompetence(string $fulltext, array $gc, array $ed, array $wa)
    {
        $sqlCondition="";
        if (!empty($gc)) {
            $sqlCondition .= " AND nsz_vseobecne_sposobilosti.vseobecne_sposobilosti IN (".implode(",", $gc).") " ;
        }
        if (!empty($ed)) {
            $sqlCondition .= " AND nsz_psv.psv IN (".implode(",", $ed).") ";
        }
        if (!empty($wa)) {
            $sqlCondition .= " AND p_skisco08_pracovna_oblast.id_prac_oblast IN (".implode(",", $wa).") ";
        }
        $fulltextCondition = false;
        $fulltext = trim($fulltext);
        if (!empty($fulltext)) {
            $fulltextCondition = true;
            $sqlCondition .= " AND (nsz_odborne_vedomosti.odb_vedomost_text LIKE :text OR nsz_odborne_zrucnosti.odb_zrucnost_text LIKE :text) ";
        }

        $sql = "SELECT count(id_nsz) as pocet, kod_oblasti, nazov
                FROM
                    (
                        SELECT
                            DISTINCT nsz.id AS id_nsz,
                            c_skisco08_oblasti.kod AS kod_oblasti,
                            c_skisco08_oblasti.nazov 
                        FROM nsz
                        JOIN nsz_vseobecne_sposobilosti ON nsz_vseobecne_sposobilosti.id_nsz=nsz.id
                        JOIN nsz_psv ON nsz_psv.id_nsz=nsz.id
                        JOIN nsz_skisco08 ON nsz_skisco08.id_nsz=nsz.id
                        JOIN p_skisco08_pracovna_oblast ON p_skisco08_pracovna_oblast.skisco08=nsz_skisco08.skisco08
                        JOIN c_skisco08_oblasti ON c_skisco08_oblasti.kod=p_skisco08_pracovna_oblast.id_prac_oblast
                        ".(
                            $fulltextCondition ?
                            "JOIN nsz_odborne_vedomosti ON nsz_odborne_vedomosti.id_nsz=nsz.id
                            JOIN nsz_odborne_zrucnosti ON nsz_odborne_zrucnosti.id_nsz=nsz.id "
                            : ""
                        )."
                        WHERE nsz.zmazane=0 AND nsz.stav=6 ".$sqlCondition."
                    ) AS t
                GROUP BY kod_oblasti 
                ORDER BY nazov";

        $statement = $this->conn->prepare($sql);
        if ($fulltextCondition) {
            $statement->bindValue(":text", "%%".$fulltext."%%");
        }

        $resultSet = $statement->executeQuery();
        $nszList = $resultSet->fetchAllAssociative();

        return $nszList;
    }





}
