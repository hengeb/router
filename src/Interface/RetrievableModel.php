<?php

namespace Hengeb\Router\Interface;

interface RetrievableModel {
    public function retrieveModel(mixed $id, string $identifierName = 'id'): static;
}
