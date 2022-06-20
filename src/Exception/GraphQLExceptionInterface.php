<?php

namespace Trexima\SriClient\Exception;

interface GraphQLExceptionInterface
{
    public function getMessage(): string;
    public function getData(): array;
    public function getGraphQLErrors(): array;
}
