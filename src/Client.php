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
use Cocur\Slugify\Slugify;

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
     * @param string $host
     * @param string $username
     * @param string $password
     * @param string $database
     * @param int $port
     * @return mixed
     * @throws Exception
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
     * @param int $id
     * @return mixed
     * @throws \Psr\Cache\InvalidArgumentException
     */
    public function getNszById(int $id)
    {
        $cacheKey = 'sc-' . md5((string)$id);
        $result = $this->cache->get($cacheKey, function (ItemInterface $item) use ($id) {
            $item->expiresAfter($this->cacheTtl);
            $resource = $this->get(sprintf('/api/nszs/%d.json', $id));
            return (string)$resource->getBody();
        });

        return $this->jsonDecode($result);
    }

    public function getNszListBySC(int $id): array
    {
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

    public function getWorkAreasWithNszCount(): array
    {
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

    /**
     * @throws Exception
     */
    public function getWorkAreasNameByIds(array $ids): array
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
        return $resultSet->fetchAllAssociative();
    }

    public function getPublishedNSZByWorkArea(int $id): array
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

    public function getWorkAreasIdFromSlug(string $slug): ?int
    {
        $slugify = new Slugify();

        $workAreas = $this->getWorkAreas();

        $workAreaId = null;
        foreach ($workAreas as $workArea) {
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
                WHERE (id <= 26 OR id > 2502) AND id != 22 AND (id >= 2300 OR id < 2200)
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
        $sqlCondition = "";
        if (!empty($gc)) {
            $sqlCondition .= " AND nsz_vseobecne_sposobilosti.vseobecne_sposobilosti IN (" . implode(",", $gc) . ") ";
        }
        if (!empty($ed)) {
            $sqlCondition .= " AND nsz_psv.psv IN (" . implode(",", $ed) . ") ";
        }
        if (!empty($wa)) {
            $sqlCondition .= " AND p_skisco08_pracovna_oblast.id_prac_oblast IN (" . implode(",", $wa) . ") ";
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
                " . (
            $fulltextCondition ?
                "JOIN nsz_odborne_vedomosti ON nsz_odborne_vedomosti.id_nsz=nsz.id
                    JOIN nsz_odborne_zrucnosti ON nsz_odborne_zrucnosti.id_nsz=nsz.id "
                : ""
            ) . "
                WHERE nsz.zmazane=0 AND nsz.stav=6 " . $sqlCondition . "
                ORDER BY c_skisco08_oblasti.nazov ASC, nsz_ekr.ekr ASC, nazov ASC";

        $statement = $this->conn->prepare($sql);
        if ($fulltextCondition) {
            $statement->bindValue(":text", "%%" . $fulltext . "%%");
        }

        $resultSet = $statement->executeQuery();
        $nszList = $resultSet->fetchAllAssociative();

        return $nszList;
    }

    public function getWorkAreaByCompetence(string $fulltext, array $gc, array $ed, array $wa)
    {
        $sqlCondition = "";
        if (!empty($gc)) {
            $sqlCondition .= " AND nsz_vseobecne_sposobilosti.vseobecne_sposobilosti IN (" . implode(",", $gc) . ") ";
        }
        if (!empty($ed)) {
            $sqlCondition .= " AND nsz_psv.psv IN (" . implode(",", $ed) . ") ";
        }
        if (!empty($wa)) {
            $sqlCondition .= " AND p_skisco08_pracovna_oblast.id_prac_oblast IN (" . implode(",", $wa) . ") ";
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
                        " . (
            $fulltextCondition ?
                "JOIN nsz_odborne_vedomosti ON nsz_odborne_vedomosti.id_nsz=nsz.id
                            JOIN nsz_odborne_zrucnosti ON nsz_odborne_zrucnosti.id_nsz=nsz.id "
                : ""
            ) . "
                        WHERE nsz.zmazane=0 AND nsz.stav=6 " . $sqlCondition . "
                    ) AS t
                GROUP BY kod_oblasti 
                ORDER BY nazov";

        $statement = $this->conn->prepare($sql);
        if ($fulltextCondition) {
            $statement->bindValue(":text", "%%" . $fulltext . "%%");
        }

        $resultSet = $statement->executeQuery();
        $nszList = $resultSet->fetchAllAssociative();

        return $nszList;
    }

    public function getNszBasic(int $id)
    {
        $res = [];
        if ($id > 0) {
            $sql = "SELECT nsz.id, nsz.kod, nsz.nazov, nsz.stav, c_stav_nsz.nazov AS stav_text, nsz.zmazane                          
                    FROM nsz
                    LEFT JOIN c_stav_nsz ON c_stav_nsz.id=nsz.stav                
                    WHERE nsz.id= :id
                    LIMIT 1";

            $statement = $this->conn->prepare($sql);
            $statement->bindValue(":id", $id, "integer");

            $resultSet = $statement->executeQuery();
            $dbRes = $resultSet->fetchAllAssociative();
            if ($dbRes) {
                $res = $dbRes[0];
            }
        }

        return $res;
    }

    public function getNszBasicByCode(int $code)
    {
        $res = [];
        if ($code > 0) {
            $sql = "SELECT nsz.id, nsz.kod, nsz.nazov, nsz.stav, c_stav_nsz.nazov AS stav_text, nsz.zmazane
                    FROM nsz
                    LEFT JOIN c_stav_nsz ON c_stav_nsz.id=nsz.stav
                    WHERE nsz.kod= :code
                    LIMIT 1";

            $statement = $this->conn->prepare($sql);
            $statement->bindValue(":code", $code, "integer");

            $resultSet = $statement->executeQuery();
            $dbRes = $resultSet->fetchAllAssociative();
            if ($dbRes) {
                $res = $dbRes[0];
            }
        }

        return $res;
    }

    public function getNszSkISCO08(int $id)
    {
        $res = [];
        if ($id > 0) {
            $sql = "SELECT nsz_skisco08.id, nsz_skisco08.skisco08, c_skisco08.nazov
                    FROM nsz_skisco08
                    LEFT JOIN c_skisco08 ON c_skisco08.kod=nsz_skisco08.skisco08
                    WHERE nsz_skisco08.id_nsz= :id";

            $statement = $this->conn->prepare($sql);
            $statement->bindValue(":id", $id, "integer");

            $resultSet = $statement->executeQuery();
            $res = $resultSet->fetchAllAssociative();
        }

        return $res;
    }

    public function getNszCharacteristic(int $id)
    {
        $res = [];
        if ($id > 0) {
            $sql = "SELECT
                        id,
                        nsz_charakteristika.text AS charakteristika,
                        nsz_charakteristika.text_podrobne AS charakteristikaPodrobne
                    FROM nsz_charakteristika 
                    WHERE id_nsz= :id_nsz
                    LIMIT 1";

            $statement = $this->conn->prepare($sql);
            $statement->bindValue(":id_nsz", $id, "integer");

            $resultSet = $statement->executeQuery();
            $res = $resultSet->fetchAllAssociative();
        }

        return $res;
    }

    public function getNszSzco(int $id)
    {
        $res = [];
        if ($id > 0) {
            $sql = "SELECT id_nsz
                    FROM nsz_szco 
                    WHERE id_nsz_vazba = :id_nsz
                    LIMIT 1";

            $statement = $this->conn->prepare($sql);
            $statement->bindValue(":id_nsz", $id, "integer");

            $resultSet = $statement->executeQuery();
            $res = $resultSet->fetchAllAssociative();
            if (empty($res)) {
                $res[] = [
                    'id_nsz' => 0
                ];
            }
        }

        return $res;
    }

    public function getNszPhoto(int $id)
    {
        $res = [];
        if ($id > 0) {
            $sql = "SELECT nsz_ilustracne_foto.id, nsz_ilustracne_foto.ilustracne_foto, nsz_ilustracne_foto.popis
                    FROM nsz_ilustracne_foto
                    WHERE nsz_ilustracne_foto.id_nsz = :id_nsz";

            $statement = $this->conn->prepare($sql);
            $statement->bindValue(":id_nsz", $id, "integer");

            $resultSet = $statement->executeQuery();
            $results = $resultSet->fetchAllAssociative();

            foreach ($results as $row) {
//                if (file_exists($this->getPath_ilustracne_foto() . $row->ilustracne_foto) )  {
                $res[] = [
                    "id" => $row['id'],
                    "photo" => $row['ilustracne_foto'],
                    "description" => $row['popis']
                ];
//                }
            }
        }

        return $res;
    }

    public function getNszSectorCouncil(int $id)
    {
        $res = [];
        if ($id > 0) {
            $sql = "SELECT
                        nsz_sr.id_sr,
                        c_sektorove_rady.nazov,
                        c_sektorove_rady.socialny_partner AS socialny_partner_kod,
                        sp.nazov AS socialny_partner
                    FROM nsz_sr
                    LEFT JOIN c_sektorove_rady ON c_sektorove_rady.id=nsz_sr.id_sr
                    LEFT JOIN c_socialny_partner sp ON (sp.id = c_sektorove_rady.socialny_partner) 
                    WHERE nsz_sr.id_nsz = :id_nsz
                    LIMIT 1";

            $statement = $this->conn->prepare($sql);
            $statement->bindValue(":id_nsz", $id, "integer");

            $resultSet = $statement->executeQuery();
            $result = $resultSet->fetchAllAssociative();

            if ($result) {
                $res = [
                    "id_sr" => $result[0]['id_sr'],
                    "sr_text" => $result[0]['nazov'],
                    "soc_partner" => $result[0]['socialny_partner'],
                    "soc_partner_kod" => $result[0]['socialny_partner_kod']
                ];
            } else {
                $res = [
                    "id_sr" => 0,
                    "sr_text" => "",
                    "soc_partner" => "",
                    "soc_partner_kod" => 0
                ];
            }
        }

        return $res;
    }

    public function getNszAlternativeNames(int $id)
    {
        $res = [];
        if ($id > 0) {
            $sql = "SELECT nsz_alt_nazov.id, nsz_alt_nazov.alt_nazov
                    FROM nsz_alt_nazov
                    WHERE nsz_alt_nazov.id_nsz = :id_nsz
                    ORDER BY nsz_alt_nazov.alt_nazov ASC";

            $statement = $this->conn->prepare($sql);
            $statement->bindValue(":id_nsz", $id, "integer");

            $resultSet = $statement->executeQuery();
            $res = $resultSet->fetchAllAssociative();
        }

        return $res;
    }

    public function getNszEducation(int $id)
    {
        $res = [];
        if ($id > 0) {
            $sql = "SELECT nsz_psv.id, nsz_psv.psv, c_psv.nazov, nsz_psv.poznamka
                    FROM nsz_psv
                    LEFT JOIN c_psv ON c_psv.id=nsz_psv.psv
                    WHERE nsz_psv.id_nsz = :id_nsz
                    LIMIT 1";

            $statement = $this->conn->prepare($sql);
            $statement->bindValue(":id_nsz", $id, "integer");

            $resultSet = $statement->executeQuery();
            $res = $resultSet->fetchAllAssociative();
        }

        return $res;
    }

    public function getNszNkr(int $id)
    {
        $res = [];
        if ($id > 0) {
            $sql = "SELECT nsz_nkr.id, nsz_nkr.id_nsz, nsz_nkr.nkr                          
                    FROM nsz_nkr                 
                    WHERE nsz_nkr.id_nsz = :id_nsz
                    LIMIT 1";

            $statement = $this->conn->prepare($sql);
            $statement->bindValue(":id_nsz", $id, "integer");

            $resultSet = $statement->executeQuery();
            $res = $resultSet->fetchAllAssociative();
        }

        return $res;
    }

    public function getNszEkr(int $id)
    {
        $res = [];
        if ($id > 0) {
            $sql = "SELECT nsz_ekr.id, nsz_ekr.ekr
                    FROM nsz_ekr
                    WHERE nsz_ekr.id_nsz = :id_nsz
                    LIMIT 1";

            $statement = $this->conn->prepare($sql);
            $statement->bindValue(":id_nsz", $id, "integer");

            $resultSet = $statement->executeQuery();
            $res = $resultSet->fetchAllAssociative();
        }

        return $res;
    }

    public function getNszIsced(int $id)
    {
        $res = [];
        if ($id > 0) {
            $sql = "SELECT nsz_isced.id, nsz_isced.id_nsz, nsz_isced.isced, c.isced AS isced_text                          
                    FROM nsz_isced
                    LEFT JOIN c_isced_hybrid AS c ON c.id=nsz_isced.isced
                    WHERE nsz_isced.id_nsz = :id_nsz
                    LIMIT 1";

            $statement = $this->conn->prepare($sql);
            $statement->bindValue(":id_nsz", $id, "integer");

            $resultSet = $statement->executeQuery();
            $res = $resultSet->fetchAllAssociative();
        }

        return $res;
    }

    public function getNszRegulatedOccupation(int $id)
    {
        $res = [];
        if ($id > 0) {
            $sql = "SELECT nsz_reg_povolanie.id, nsz_reg_povolanie.reg_povolanie, nsz_reg_povolanie.poznamka
                    FROM nsz_reg_povolanie
                    WHERE nsz_reg_povolanie.id_nsz = :id_nsz
                    LIMIT 1";

            $statement = $this->conn->prepare($sql);
            $statement->bindValue(":id_nsz", $id, "integer");

            $resultSet = $statement->executeQuery();
            $result = $resultSet->fetchAllAssociative();

            $res = $result[0];

            if ($result) {
                $sql = "SELECT
                            nsz_reg_povolanie_pravny_predpis.id,
                            nsz_reg_povolanie_pravny_predpis.id_pravny_predpis,
                            nsz_reg_povolanie_pravny_predpis.pravny_predpis,
                            IF(nsz_reg_povolanie_pravny_predpis.pravny_predpis > 0, c_pravny_predpis.text, nsz_reg_povolanie_pravny_predpis.pravny_predpis) AS pravny_predpis_text
                        FROM nsz_reg_povolanie_pravny_predpis
                        LEFT JOIN c_pravny_predpis ON c_pravny_predpis.id=nsz_reg_povolanie_pravny_predpis.id_pravny_predpis
                        WHERE nsz_reg_povolanie_pravny_predpis.id_nsz = :id_nsz
                        ORDER BY c_pravny_predpis.poradie ASC, c_pravny_predpis.text ASC";

                $statement = $this->conn->prepare($sql);
                $statement->bindValue(":id_nsz", $id, "integer");

                $resultSet = $statement->executeQuery();

                $res['pravne_predpisy'] = $resultSet->fetchAllAssociative();
            }
        }

        return $res;
    }

    public function getNszPraxis(int $id)
    {
        $res = [];
        if ($id > 0) {
            $sql = "SELECT
                        nsz_prax.id,
                        nsz_prax.prax,
                        c_prax.nazov AS prax_text,
                        nsz_prax.dlzka,
                        IFNULL(c_dlzka_praxe.nazov, 'neuvedené') AS prax_dlzka_text,
                        poznamka
                    FROM nsz_prax 
                    LEFT JOIN c_dlzka_praxe ON c_dlzka_praxe.id=nsz_prax.dlzka
                    LEFT JOIN c_prax ON c_prax.id=nsz_prax.prax
                    WHERE nsz_prax.id_nsz = :id_nsz
                    LIMIT 1";

            $statement = $this->conn->prepare($sql);
            $statement->bindValue(":id_nsz", $id, "integer");

            $resultSet = $statement->executeQuery();
            $result = $resultSet->fetchAllAssociative();

            if ($result) {
                $sql = "SELECT
                            nsz_prax_pravny_predpis.id,
                            nsz_prax_pravny_predpis.id_pravny_predpis,
                            nsz_prax_pravny_predpis.pravny_predpis,
                            IF(nsz_prax_pravny_predpis.id_pravny_predpis > 0, c_pravny_predpis.text, nsz_prax_pravny_predpis.pravny_predpis) AS pravny_predpis_text
                        FROM nsz_prax_pravny_predpis
                        LEFT JOIN c_pravny_predpis ON c_pravny_predpis.id=nsz_prax_pravny_predpis.id_pravny_predpis
                        WHERE nsz_prax_pravny_predpis.id_nsz = :id_nsz                   
                        ORDER BY c_pravny_predpis.poradie ASC, c_pravny_predpis.text ASC";

                $statement = $this->conn->prepare($sql);
                $statement->bindValue(":id_nsz", $id, "integer");

                $resultSet = $statement->executeQuery();

                $res = $result[0];
                $res['pravne_predpisy'] = $resultSet->fetchAllAssociative();
            }
        }

        return $res;
    }

    public function getNszIsco(int $id)
    {
        $res = [];
        if ($id > 0) {
            $sql = "SELECT nsz_isco08.id, nsz_isco08.isco08, c_isco08.nazov
                    FROM nsz_isco08
                    LEFT JOIN c_isco08 ON c_isco08.kod=nsz_isco08.isco08
                    WHERE nsz_isco08.id_nsz = :id_nsz";

            $statement = $this->conn->prepare($sql);
            $statement->bindValue(":id_nsz", $id, "integer");

            $resultSet = $statement->executeQuery();
            $res = $resultSet->fetchAllAssociative();
        }

        return $res;
    }

    public function getNszNace(int $id)
    {
        $res = [];
        if ($id > 0) {
            $sql = "SELECT nsz_sknacerev2.id, nsz_sknacerev2.sknacerev2, c_sknacerev2.kod, c_sknacerev2.nazov
                    FROM nsz_sknacerev2
                    LEFT JOIN c_sknacerev2 ON c_sknacerev2.id=nsz_sknacerev2.sknacerev2
                    WHERE nsz_sknacerev2.id_nsz = :id_nsz";

            $statement = $this->conn->prepare($sql);
            $statement->bindValue(":id_nsz", $id, "integer");

            $resultSet = $statement->executeQuery();
            $res = $resultSet->fetchAllAssociative();
        }

        return $res;
    }

    public function getNszOccupation(int $id)
    {
        $res = [];
        if ($id > 0) {
            $sql = "SELECT DISTINCT c_skisco08_povolania.povolanie
                    FROM nsz_skisco08
                    JOIN c_skisco08_povolania ON c_skisco08_povolania.skisco08=nsz_skisco08.skisco08
                    WHERE nsz_skisco08.id_nsz = :id_nsz                                     
                    ORDER BY c_skisco08_povolania.povolanie ASC";

            $statement = $this->conn->prepare($sql);
            $statement->bindValue(":id_nsz", $id, "integer");

            $resultSet = $statement->executeQuery();
            $res = $resultSet->fetchAllAssociative();
        }

        return $res;
    }

    public function getNszCompetenceGeneral(int $id)
    {
        $res = [];
        if ($id > 0) {
            $sql = "SELECT
                        nsz_vseobecne_sposobilosti.id,
                        nsz_vseobecne_sposobilosti.vseobecne_sposobilosti,
                        c_vseobecne_sposobilosti.nazov,
                        nsz_vseobecne_sposobilosti.uroven_ovladania,
                        (
                            SELECT COUNT(*) 
                            FROM pripomienky 
                            WHERE
                                pripomienky.vymazane=0 AND 
                                pripomienky.id_nsz=nsz_vseobecne_sposobilosti.id_nsz AND
                                id_nsz_polozky= 12 AND
                                id_kompetencie= nsz_vseobecne_sposobilosti.id
                        ) AS pocetPripomienok,
                        c_vseobecne_sposobilosti_uroven_popis.nazov AS uroven_text
                    FROM nsz_vseobecne_sposobilosti
                    LEFT JOIN c_vseobecne_sposobilosti ON c_vseobecne_sposobilosti.id=nsz_vseobecne_sposobilosti.vseobecne_sposobilosti
                    LEFT JOIN c_vseobecne_sposobilosti_uroven_popis ON c_vseobecne_sposobilosti_uroven_popis.id_vs=nsz_vseobecne_sposobilosti.vseobecne_sposobilosti AND c_vseobecne_sposobilosti_uroven_popis.uroven=nsz_vseobecne_sposobilosti.uroven_ovladania                   
                    WHERE nsz_vseobecne_sposobilosti.id_nsz = :id_nsz
                    GROUP BY nsz_vseobecne_sposobilosti.id
                    ORDER BY nsz_vseobecne_sposobilosti.uroven_ovladania DESC, c_vseobecne_sposobilosti.nazov ASC";

            $statement = $this->conn->prepare($sql);
            $statement->bindValue(":id_nsz", $id, "integer");

            $resultSet = $statement->executeQuery();
            $result = $resultSet->fetchAllAssociative();

            if ($result) {
                foreach ($result as $row) {
                    if ($row['vseobecne_sposobilosti'] != 22 && ($row['vseobecne_sposobilosti'] >= 2300 || $row['vseobecne_sposobilosti'] < 2200)) {
                        $res[] = [
                            "id" => $row['id'],
                            "vseobecne_sposobilosti" => $row['vseobecne_sposobilosti'],
                            "vseobecne_sposobilosti_text" => $row['nazov'],
                            "uroven_ovladania" => $row['uroven_ovladania'],
                            "pocetPripomienok" => $row['pocetPripomienok'],
                            "uroven_text" => $row['uroven_text']
                        ];
                    }
                }
            }
        }

        return $res;
    }

    public function getCompetenceGeneralDescription(): array
    {
        $sql = "SELECT id_vs, uroven, nazov FROM c_vseobecne_sposobilosti_uroven_popis";

        $statement = $this->conn->prepare($sql);

        $resultSet = $statement->executeQuery();
        $result = $resultSet->fetchAllAssociative();

        $res = [];
        foreach ($result as $item) {
            if (!isset($res[$item['id_vs']])) {
                $res[$item['id_vs']] = [];
            }

            if (!isset($res[$item['id_vs']][$item['uroven']])) {
                $res[$item['id_vs']][$item['uroven']] = $item['nazov'];
            } else {
                $res[$item['id_vs']][$item['uroven']] .= ', ' . $item['nazov'];
            }
        }

        return $res;
    }

    public function getNszKnowledge(int $id): array
    {
        $res = [];
        if ($id > 0) {
            $sql = "SELECT
                        nsz_odborne_vedomosti_navrh.id,
                        nsz_odborne_vedomosti_navrh.text,
                        nsz_odborne_vedomosti_navrh.ekr,
                        nsz_odborne_vedomosti_navrh.specifikacia,
                        nsz_odborne_vedomosti_navrh.zamietnute,
                        nsz_odborne_vedomosti_navrh.dovod_zamietnutia,
                        (
                            SELECT COUNT(*)
                            FROM pripomienky
                            WHERE pripomienky.vymazane=0 AND
                                pripomienky.id_nsz=nsz_odborne_vedomosti_navrh.id_nsz AND
                                id_nsz_polozky= 16 AND
                                id_kompetencie= nsz_odborne_vedomosti_navrh.id
                        ) AS pocetPripomienok
                    FROM nsz_odborne_vedomosti_navrh
                    WHERE flag=1 AND nsz_odborne_vedomosti_navrh.id_nsz = :id_nsz
                    ORDER BY nsz_odborne_vedomosti_navrh.id ASC";
            $statement = $this->conn->prepare($sql);
            $statement->bindValue(":id_nsz", $id, "integer");

            $resultSet = $statement->executeQuery();
            $ovN = $resultSet->fetchAllAssociative();
            /*
                        $sql = "SELECT
                                    nsz_odborne_vedomosti_navrh.id,
                                    nsz_odborne_vedomosti_navrh.id_nsz,
                                    nsz_odborne_vedomosti_navrh.id_ov,
                                    c_ktp_ozn_4.nazov,
                                    nsz_odborne_vedomosti_navrh.text,
                                    nsz_odborne_vedomosti_navrh.ekr,
                                    nsz_odborne_vedomosti_navrh.specifikacia,
                                    nsz_odborne_vedomosti_navrh.zamietnute,
                                    nsz_odborne_vedomosti_navrh.dovod_zamietnutia,
                                    (
                                        SELECT COUNT(*)
                                        FROM pripomienky
                                        WHERE pripomienky.vymazane=0 AND
                                            pripomienky.id_nsz=nsz_odborne_vedomosti_navrh.id_nsz AND
                                            id_nsz_polozky= 16 AND
                                            id_kompetencie= nsz_odborne_vedomosti_navrh.id
                                    ) AS pocetPripomienok
                                FROM nsz_odborne_vedomosti_navrh
                                LEFT JOIN c_ktp_ozn_4 ON c_ktp_ozn_4.id_ozn_4=nsz_odborne_vedomosti_navrh.id_ov
                                WHERE flag=2 AND nsz_odborne_vedomosti_navrh.id_nsz = :id_nsz
                                ORDER BY nsz_odborne_vedomosti_navrh.id ASC";
                        $statement = $this->conn->prepare($sql);
                        $statement->bindValue(":id_nsz", $id, "integer");

                        $resultSet = $statement->executeQuery();
                        $ovNZ = $resultSet->fetchAllAssociative();
            */
            $ovNZ = [];

            //odborne vedomosti
            $sql = "SELECT
                        nsz_odborne_vedomosti.id,
                        nsz_odborne_vedomosti.odb_vedomost,
                        nsz_odborne_vedomosti.odb_vedomost_text,
                        nsz_odborne_vedomosti.ekr,
                        nsz_odborne_vedomosti.specifikacia
                    FROM nsz_odborne_vedomosti                   
                    WHERE nsz_odborne_vedomosti.id_nsz = :id_nsz
                    ORDER BY nsz_odborne_vedomosti.ekr DESC, nsz_odborne_vedomosti.id ASC";
            $statement = $this->conn->prepare($sql);
            $statement->bindValue(":id_nsz", $id, "integer");

            $resultSet = $statement->executeQuery();
            $result = $resultSet->fetchAllAssociative();

            foreach ($result as $row) {
                $text = $row['odb_vedomost_text'];
                foreach ($ovNZ as $rZ) {
                    if ($rZ['id_ov'] == $row['odb_vedomost'] && $rZ['zamietnute'] == 0) {
                        $text = $rZ['text'];
                    }
                }

                $res[] = [
                    "id" => $row['id'],
                    "odborneVedomosti" => $row['odb_vedomost'],
                    "odborneVedomosti_text" => $text,
                    "odborneVedomosti_kod" => '',
                    "ekr" => $row['ekr'],
                    "specifikacia" => $row['specifikacia']
                ];
            }

            foreach ($ovN as $rN) {
                $res[] = [
                    "id" => $rN['id'],
                    "odborneVedomosti" => 0,
                    "odborneVedomosti_text" => $rN['text'],
                    "odborneVedomosti_kod" => "",
                    "ekr" => $rN['ekr'],
                    "specifikacia" => $rN['specifikacia']
                ];
            }
        }

        return $res;
    }

    public function getNszIsSzco(int $id)
    {
        $res = 0;
        if ($id > 0) {
            $sql = "SELECT id FROM nsz_szco WHERE id_nsz = :id_nsz LIMIT 1";
            $statement = $this->conn->prepare($sql);
            $statement->bindValue(":id_nsz", $id, "integer");

            $resultSet = $statement->executeQuery();
            $result = $resultSet->fetchAllAssociative();
            $res = 0;
            if ($result) {
                $res = 1;
            }
        }

        return $res;
    }

    public function getKnowledgeSZCO()
    {
        $sql = "SELECT odb_vedomost, specifikacia FROM c_odborne_vedomosti_szco";

        $statement = $this->conn->prepare($sql);

        $resultSet = $statement->executeQuery();
        $res = $resultSet->fetchAllAssociative();

        return $res;
    }

    public function getSkillSZCO()
    {
        $sql = "SELECT odb_zrucnost, specifikacia FROM c_odborne_zrucnosti_szco";

        $statement = $this->conn->prepare($sql);

        $resultSet = $statement->executeQuery();
        $res = $resultSet->fetchAllAssociative();

        return $res;
    }

    public function getEmployerProtection()
    {
        $sql = "SELECT id, nazov FROM c_ochrana_zamestnancov";

        $statement = $this->conn->prepare($sql);

        $resultSet = $statement->executeQuery();
        $res = $resultSet->fetchAllAssociative();

        return $res;
    }

    public function getNszSkill(int $id)
    {
        $res = [];
        if ($id > 0) {
            $sql = "SELECT
                        nsz_odborne_zrucnosti_navrh.id,
                        nsz_odborne_zrucnosti_navrh.text,
                        nsz_odborne_zrucnosti_navrh.ekr,
                        nsz_odborne_zrucnosti_navrh.specifikacia,
                        nsz_odborne_zrucnosti_navrh.zamietnute,
                        nsz_odborne_zrucnosti_navrh.dovod_zamietnutia,
                        (
                            SELECT COUNT(*)
                            FROM pripomienky
                            WHERE pripomienky.vymazane=0 AND
                                pripomienky.id_nsz=nsz_odborne_zrucnosti_navrh.id_nsz AND
                                id_nsz_polozky= 17 AND
                                id_kompetencie= nsz_odborne_zrucnosti_navrh.id
                        ) AS pocetPripomienok
                    FROM nsz_odborne_zrucnosti_navrh
                    WHERE flag=1 AND nsz_odborne_zrucnosti_navrh.id_nsz = :id_nsz
                    ORDER BY nsz_odborne_zrucnosti_navrh.id ASC";
            $statement = $this->conn->prepare($sql);
            $statement->bindValue(":id_nsz", $id, "integer");

            $resultSet = $statement->executeQuery();
            $ozN = $resultSet->fetchAllAssociative();
            /*
                        $sql = "SELECT
                                    nsz_odborne_zrucnosti_navrh.id,
                                    nsz_odborne_zrucnosti_navrh.id_nsz,
                                    nsz_odborne_zrucnosti_navrh.id_oz,
                                    c_ktp_od_6.nazov,
                                    nsz_odborne_zrucnosti_navrh.text,
                                    nsz_odborne_zrucnosti_navrh.ekr,
                                    nsz_odborne_zrucnosti_navrh.specifikacia,
                                    nsz_odborne_zrucnosti_navrh.zamietnute,
                                    nsz_odborne_zrucnosti_navrh.dovod_zamietnutia,
                                    (
                                        SELECT COUNT(*)
                                        FROM pripomienky
                                        WHERE pripomienky.vymazane=0 AND
                                            pripomienky.id_nsz=nsz_odborne_zrucnosti_navrh.id_nsz AND
                                            id_nsz_polozky= 17 AND
                                            id_kompetencie= nsz_odborne_zrucnosti_navrh.id
                                    ) AS pocetPripomienok
                                FROM nsz_odborne_zrucnosti_navrh
                                LEFT JOIN c_ktp_od_6 ON c_ktp_od_6.id_od_6=nsz_odborne_zrucnosti_navrh.id_oz
                                WHERE flag=2 AND nsz_odborne_zrucnosti_navrh.id_nsz = :id_nsz
                                ORDER BY nsz_odborne_zrucnosti_navrh.id ASC";
                        $statement = $this->conn->prepare($sql);
                        $statement->bindValue(":id_nsz", $id, "integer");

                        $resultSet = $statement->executeQuery();
                        $ozNZ = $resultSet->fetchAllAssociative();
            */
            $ozNZ = [];

            //odborne zrucnosti
            $sql = "SELECT
                        nsz_odborne_zrucnosti.id,
                        nsz_odborne_zrucnosti.odb_zrucnost,
                        nsz_odborne_zrucnosti.odb_zrucnost_text,
                        nsz_odborne_zrucnosti.ekr,
                        nsz_odborne_zrucnosti.specifikacia
                    FROM nsz_odborne_zrucnosti
                    WHERE nsz_odborne_zrucnosti.id_nsz = :id_nsz
                    ORDER BY nsz_odborne_zrucnosti.ekr DESC";
            $statement = $this->conn->prepare($sql);
            $statement->bindValue(":id_nsz", $id, "integer");

            $resultSet = $statement->executeQuery();
            $result = $resultSet->fetchAllAssociative();

            foreach ($result as $row) {
                $text = $row['odb_zrucnost_text'];
                foreach ($ozNZ as $rZ) {
                    if ($rZ['id_oz'] == $row['odb_zrucnost'] && $rZ['zamietnute'] == 0) {
                        $text = $rZ['text'];
                    }
                }

                $res[] = [
                    "id" => $row['id'],
                    "odborneZrucnosti" => $row['odb_zrucnost'],
                    "odborneZrucnosti_text" => $text,
//                    "odborneZrucnosti_kod" => $row['kod_zrucnosti'],
                    "ekr" => $row['ekr'],
                    "specifikacia" => $row['specifikacia']
                ];
            }

            foreach ($ozN as $rN) {
                $res[] = [
                    "id" => $rN['id'],
                    "odborneZrucnosti" => 0,
                    "odborneZrucnosti_text" => $rN['text'],
                    "odborneZrucnosti_kod" => "",
                    "ekr" => $rN['ekr'],
                    "specifikacia" => $rN['specifikacia']
                ];
            }
        }

        return $res;
    }

    public function getNszCertificates(int $id)
    {
        $res = [];
        if ($id > 0) {
            $sql = "SELECT
                        nsz_certifikaty.id,
                        nsz_certifikaty.certifikat,
                        c_certifikaty.text AS certifikat_text,
                        nsz_certifikaty.text,
                        nsz_certifikaty.nutny_vyhodny,
                        nsz_certifikaty.specifikacia
                    FROM nsz_certifikaty
                    LEFT JOIN c_certifikaty ON c_certifikaty.id=nsz_certifikaty.certifikat
                    WHERE nsz_certifikaty.id_nsz = :id_nsz
                    ORDER BY nsz_certifikaty.nutny_vyhodny DESC, nsz_certifikaty.id DESC";
            $statement = $this->conn->prepare($sql);
            $statement->bindValue(":id_nsz", $id, "integer");

            $resultSet = $statement->executeQuery();
            $result = $resultSet->fetchAllAssociative();

            $certifikaty = [];
            foreach ($result as $row) {
                $certifikaty[] = [
                    "id" => $row['id'],
                    "certifikat" => $row['certifikat'],
                    "certifikat_text" => ($row['certifikat'] > 0 ? $row['certifikat_text'] : $row['text']),
                    "text" => $row['text'],
                    "nutny_vyhodny" => $row['nutny_vyhodny'],
                    "specifikacia" => $row['specifikacia']
                ];
            }

            $nevyzaduje_sa = 0;
            $sql = "SELECT
                        nsz_certifikaty_nevyzaduje_sa.id,
                        nsz_certifikaty_nevyzaduje_sa.id_nsz,
                        nsz_certifikaty_nevyzaduje_sa.nevyzaduje_sa,
                        nsz_certifikaty_nevyzaduje_sa.posledna_uprava
                    FROM nsz_certifikaty_nevyzaduje_sa
                    WHERE nsz_certifikaty_nevyzaduje_sa.id_nsz = :id_nsz
                    LIMIT 1";

            $statement = $this->conn->prepare($sql);
            $statement->bindValue(":id_nsz", $id, "integer");

            $resultSet = $statement->executeQuery();
            $result = $resultSet->fetchAllAssociative();

            if ($result) {
                $nevyzaduje_sa = $result[0]['nevyzaduje_sa'];
            }
            $res = [
                "certifikaty" => $certifikaty,
                "nevyzaduje_sa" => $nevyzaduje_sa
            ];
        }

        return $res;
    }

    public function getWorkAreaBySkIsco08(string $skisco08)
    {
        $res = [];
        if ($skisco08 > 0) {
            $sql = "SELECT c_skisco08_oblasti.kod AS id, c_skisco08_oblasti.nazov
                    FROM p_skisco08_pracovna_oblast
                    JOIN c_skisco08_oblasti ON c_skisco08_oblasti.kod = p_skisco08_pracovna_oblast.id_prac_oblast
                    WHERE skisco08 = :skisco08";
            $statement = $this->conn->prepare($sql);
            $statement->bindValue(":skisco08", $skisco08);

            $resultSet = $statement->executeQuery();
            $res = $resultSet->fetchAllAssociative();
        }

        return $res;
    }

    public function getNSZfromSRIGraphQL(int $nszCode, array $fieldsToExtract = [])
    {
        $fields = [
            'id',
            '_id',
            'sectorCouncil',
            'code',
            'revision',
            'title',
            'description',
            'isced',
            'skkr',
            'kovLevel',
            'practiceMonths',
            'practiceRecommendation',
            'lawsDescription',
            'practicesDescription',
            'practiceLengthDescription',
            'kovLevelDescription',
            'titleImage',
            'skkrMin',
            'iscedMin',
            'kovLevelMin',
            'skkrMax',
            'iscedMax',
            'kovLevelMax',
            'kovLevelRangeDescription',
            'escos',
            'selfEmployment',
            'alternativeTitles',
            'certificates',
            'laws',
            'workEquipments',
            'workProfiles',
            'practices',
            'selfEmploymentPacks',
        ];

        $structures = [
            'sectorCouncil' => '{
                id,
                code,
                title,
                guarantor
            }',
            'escos' => '(first: 1, last: 100) {
              edges {
                node {
                  escoId
                }
              }
            }',
            'alternativeTitles' => '{
                edges {
                    node {
                        id,
                        title,
                        language
                    }
                }
            }',
            'certificates' => '{
                edges {
                    node {
                        id,
                        _id,
                        required,
                        description,
                        certificate {
                            id,
                            title,
                            category,
                            kind
                        }
                    }
                }
            }',
            'laws' => '{
                edges {
                    node {
                        law {
                            id,
                            title
                        }
                    }
                }
            }',
            'workEquipments' => '{
                edges {
                    node {
                        workEquipment {
                            code,
                            title,
                            level,
                            parent {
                                code,
                                title,
                                level
                            }
                        }
                    }
                }
            }',
            'workProfiles' => '{
                edges {
                    node {
                        workProfile {
                            id,
                            title,
                            description,
                            category
                        }
                    }
                }
            }',
            'practices' => '{
                edges {
                    node {
                        id,
                        _id,
                        required,
                        law {
                            title
                        }
                    }
                }
            }',
            'selfEmploymentPacks' => '{
                edges {
                    node {
                        id,
                        selfEmploymentPack {
                            id,
                            _id,
                            title
                        }
                    }
                }
            }',
        ];

        if ($fieldsToExtract) {
            $flippedFields = array_flip($fields);

            foreach ($fieldsToExtract as $index => $field) {
                if (!isset($flippedFields[$field])) {
                    unset($fieldsToExtract[$index]);
                } elseif ($structure = $structures[$field] ?? null) {
                    $fieldsToExtract[$index] .= $structure;
                }
            }
        } else {
            foreach ($fields as $index => $field) {
                if ($structure = $structures[$field] ?? null) {
                    $fields[$index] .= $structure;
                }
            }

            $fieldsToExtract = $fields;
        }

        $string = sprintf("{
            nszs (code: %d) {
                edges {
                    node {
                        %s
                    }
                }
            }
        }", $nszCode, implode(',', $fieldsToExtract));

        return $this->getGraphQl('{"query": "query ' . str_replace(array("\n", "\r"), '', $string) . '"}');
    }

    /**
     * @param int $nszCode
     * @param int $nszId
     * @return array
     * @throws InvalidArgumentException
     */
    public function getNSZInnovationsfromSRIGraphQL(int $nszCode, int $nszId)
    {
        if (!$nszCode || !$nszId) {
            throw new InvalidArgumentException('Invalid parameters');
        }

        $string = sprintf("{
            innovations:nszs (code: %d) {
                edges {
                    node {
                        innovations(status_list: [1,2]) {
                            edges {
                                node {
                                    innovation {
                                        _id
                                        title
                                        description
                                        titleImage
                                        category {
                                            _id
                                        }
                                        nszDescriptionInnovation(nsz_id: %d) {
                                            totalCount
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
            competencyModel:nszs (code: %d) {
                edges {
                    node {
                        innovations(status_list: [1,2]) {
                            edges {
                                node {
                                    innovation {
                                        nszKnowledgeInnovation(nsz_id: %d) {
                                            edges {
                                                node {
                                                    nszKnowledge {
                                                        knowledge {
                                                            title
                                                        }
                                                    }
                                                }
                                            }
                                        }
                                        nszSkillInnovation(nsz_id: %d) {
                                            edges {
                                                node {
                                                    nszSkill {
                                                        skill {
                                                            title
                                                        }
                                                    }
                                                }
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
            expertActivities:nszs (code: %d) {
                edges {
                    node {
                        innovations(status_list: [1,2]) {
                            edges {
                                node {
                                    innovation {
                                        nszExpertActivityKnowledgeInnovation(nsz_id: %d) {
                                            edges {
                                                node {
                                                    nszExpertActivityKnowledge {
                                                        knowledge {
                                                            title
                                                        }
                                                    }
                                                }
                                            }
                                        }
                                        nszExpertActivitySkillInnovation(nsz_id: %d) {
                                            edges {
                                                node {
                                                    nszExpertActivitySkill {
                                                        skill {
                                                            title
                                                        }
                                                    }
                                                }
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }", $nszCode, $nszId, $nszCode, $nszId, $nszId, $nszCode, $nszId, $nszId);

        $nszInnovations = $this->getGraphQl('{"query": "query ' . str_replace(array("\n", "\r"), '', $string) . '"}');

        $result = [];

        if ($innovationsData = $nszInnovations['data']['innovations']['edges'][0]['node']['innovations']['edges']) {
            $competencyModelData = $nszInnovations['data']['competencyModel']['edges'][0]['node']['innovations']['edges'];
            $expertActivitiesData = $nszInnovations['data']['expertActivities']['edges'][0]['node']['innovations']['edges'];

            foreach ($innovationsData as $index => &$item) {
                $innovation = &$item['node']['innovation'];

                $innovation += $competencyModelData[$index]['node']['innovation'];
                $innovation += $expertActivitiesData[$index]['node']['innovation'];

                unset($item, $innovation);
            }

            $result = $innovationsData;
        }

        return $result;
    }

    public function searchNszByFulltext(string $search)
    {
        $res = [];
        if ($search) {
            $sql = 'SELECT nsz.id,nsz.nazov
                FROM nsz
                LEFT JOIN nsz_alt_nazov ON nsz_alt_nazov.id_nsz=nsz.id
                LEFT JOIN nsz_skisco08 ON nsz_skisco08.id_nsz=nsz.id
                LEFT JOIN c_skisco08 ON c_skisco08.kod=nsz_skisco08.skisco08
                LEFT JOIN c_skisco08_alternativne_nazvy ON c_skisco08_alternativne_nazvy.skisco08=nsz_skisco08.skisco08
                WHERE nsz.stav=6 AND
                    (nsz.nazov LIKE :text OR
                     nsz_alt_nazov.alt_nazov LIKE :text OR
                     c_skisco08.nazov LIKE :text OR
                     c_skisco08_alternativne_nazvy.nazov LIKE :text  OR
                     nsz_skisco08.skisco08 LIKE :text
                    )
                GROUP BY nsz.id
                ORDER BY nsz.nazov ASC;';
            $statement = $this->conn->prepare($sql);
            $statement->bindValue(":text", '%' . $search . '%');

            $resultSet = $statement->executeQuery();
            $res = $resultSet->fetchAllAssociative();
        }

        return $res;
    }
}
