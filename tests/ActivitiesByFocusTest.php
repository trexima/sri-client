<?php

namespace Trexima\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Trexima\SriClient\v2\Client;

final class ActivitiesByFocusTest extends TestCase
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

    public function testActivitiesByFocus(): void
    {
        // test without organization filter
        $organizationActivities = $this->sriClient->getActivitiesByFocus();
        $this->assertGreaterThanOrEqual(0, count($organizationActivities));

        // test with organization filter
        $organizationActivities = $this->sriClient->getActivitiesByFocus($_ENV['TEST_ORGANIZATION_ID']);
        $this->assertGreaterThanOrEqual(0, count($organizationActivities));
    }
}
