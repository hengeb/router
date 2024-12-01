<?php

namespace Hengeb\Router\Interface;

interface CurrentUserInterface {
    public function isLoggedIn(): bool;
}
