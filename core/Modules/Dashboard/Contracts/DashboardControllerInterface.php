<?php

declare(strict_types=1);

namespace Core\Modules\Dashboard\Contracts;

interface DashboardControllerInterface
{
    public function index(): void;
}
