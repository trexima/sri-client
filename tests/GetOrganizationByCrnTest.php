<?php

namespace Trexima\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Trexima\SriClient\v2\Client;

final class GetOrganizationByCrnTest extends TestCase
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

    public function testGetOrganizationByCrn(): void
    {
        $organization = $this->sriClient->getOrganizationByCrn($_ENV['TEST_ORGANIZATION_CRN']);
        $this->assertIsArray($organization);
        $organization = $this->sriClient->getOrganizationByCrn('XXX');
        $this->assertNull($organization);
    }
}
