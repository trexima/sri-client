<?php

namespace Trexima\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Trexima\SriClient\Exception\GraphQLException;
use Trexima\SriClient\v2\Client;

final class GraphQLTest extends TestCase
{
    private $sriClient;
    private $parameterExtractor;

    public function __construct(?string $name = null, array $data = [], $dataName = '')
    {
        parent::__construct($name, $data, $dataName);
        $cache = new ArrayAdapter();
        $this->parameterExtractor = new \Trexima\SriClient\MethodParameterExtractor($cache);
        $this->sriClient = new Client($_ENV['SRI_URL'], $_ENV['SRI_API_KEY'], $this->parameterExtractor, $cache);
    }

    public function testGetActivityDetail(): void
    {
        $query = sprintf('
            {
                activitiesTimeline (id: \"/api/activities_timeline/%s\") {
                    id,
                    name,
                    content,
                    dateFrom,
                    dateTo
                }
            }', $_ENV['TEST_ACTIVITY_ID']);
        $graphQLquery = '{"query": "query ' . str_replace(array("\n", "\r"), '', $query) . '"}';
        $activity = $this->sriClient->getGraphQL($graphQLquery);
        $this->assertGreaterThan(0, count($activity));

        // test throw error
        try {
            // bad qraphql query
            $query = '
            {
                activitiesTimeline (id: \"/api/activities_timeline/") {
                    id,
                    XXX
                }
            }';
            $graphQLquery = '{"query": "query ' . str_replace(array("\n", "\r"), '', $query) . '"}';
            $activity = $this->sriClient->getGraphQL($graphQLquery);
        } catch (GraphQLException $e) {
            $this->assertNotEmpty($e->getMessage());
        }
    }
}
