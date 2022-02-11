<?php

namespace Trexima\SriClient;

use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

class MethodParameterExtractor
{
    /**
     * @var CacheInterface
     */
    private $cache;

    public function __construct(CacheInterface $cache)
    {
        $this->cache = $cache;
    }

    /**
     * Extract method parameters name to array
     *
     * @param string $className
     * @param string $methodName
     * @return array
     * @throws \Psr\Cache\InvalidArgumentException
     * @throws \ReflectionException
     */
    public function extract(string $className, string $methodName)
    {
        return $this->cache->get(sprintf('method-parameter-extractor-%s', crc32($className . $methodName)), function (ItemInterface $item) use ($className, $methodName) {
            $reflection = new \ReflectionMethod($className, $methodName);
            $reflectionParameters = $reflection->getParameters();
            $parameters = [];
            foreach ($reflectionParameters as $reflectionParameter) {
                $parameters[$reflectionParameter->getPosition()] = str_replace('_', '.', $reflectionParameter->getName());
            }
            return $parameters;
        });
    }
}