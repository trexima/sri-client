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

    public function getNSZfromSRIGraphQL(int $nszId, array $fieldsToExtract = [])
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

        $string = sprintf('{
            nsz (id: "api/nszs/%d") {
                %s
            }
        }', $nszId, implode(',', $fieldsToExtract));

        return $this->getGraphQl('{"query": "query ' . addslashes(str_replace(["\n", "\r"], '', $string)) . '"}');
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
