<?php

namespace Trexima\Tests;

use GuzzleHttp\Exception\GuzzleException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Trexima\SriClient\v2\Client;

final class GetActivityDetailTest extends TestCase
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
        $activity = $this->sriClient->getActivityDetail($_ENV['TEST_ACTIVITY_ID']);
        $this->assertGreaterThan(0, count($activity));

        // test error not found
        try {
            $activity = $this->sriClient->getActivityDetail('XXXX');
        } catch (GuzzleException $e) {
            $this->assertEquals(404, $e->getCode());
        }
    }
}
