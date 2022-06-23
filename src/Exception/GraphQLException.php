<?php

namespace Trexima\SriClient\Exception;

use Exception;

class GraphQLException extends Exception implements GraphQLExceptionInterface
{
    private array $data;
    private array $errors;

    public function __construct(array $data, array $errors)
    {
        $this->data = $data;
        $this->errors = $errors;

        $message = 'GraphQL errors';
    
        parent::__construct($message);
    }

    public function getData(): array {
        return $this->data;
    }

    public function getGraphQLErrors(): array {
        return $this->errors;
    }

}
