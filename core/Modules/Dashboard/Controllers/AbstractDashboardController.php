<?php

declare(strict_types=1);

namespace Core\Modules\Dashboard\Controllers;

use Core\Http\Context;
use Core\Modules\Dashboard\Contracts\DashboardControllerInterface;
use Core\View\View;

abstract class AbstractDashboardController implements DashboardControllerInterface
{
    /**
     * @param array<string, mixed> $viewData
     */
    protected function display(string $viewName, array $viewData, string $title): void
    {
        $appRoot = defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__, 4);
        require_once $appRoot . '/config/view.php';

        $meta = Context::resolveWithMeta();
        $publicBase = class_exists(\AppHelpers::class)
            ? rtrim(\AppHelpers::getPublicUrl(), '/')
            : (defined('URL_BASE') ? rtrim((string) URL_BASE, '/') : '');
        $indexBase = defined('URL_BASE') ? URL_BASE . 'index.php' : 'index.php';

        $user = class_exists(\Auth::class) ? \Auth::user() : null;
        $userLabel = is_array($user)
            ? trim((string) ($user['nombre'] ?? $user['username'] ?? $user['email'] ?? ''))
            : '';

        View::display(
            $viewName,
            array_merge($viewData, [
                'contextType' => $meta['type'],
                'contextRole' => $meta['role'],
            ]),
            'layouts/main',
            [
                'title' => $title,
                'assetBase' => $publicBase,
                'dashboardHref' => $indexBase . '?page=home',
                'userLabel' => $userLabel,
                'menu' => $this->buildMenu($indexBase, true),
                'menuMobile' => $this->buildMenu($indexBase, true),
            ]
        );
    }

    /**
     * @return list<array{label: string, href: string, icon: string, active: bool}>
     */
    protected function buildMenu(string $indexBase, bool $homeActive): array
    {
        return [
            [
                'label' => 'Inicio',
                'href' => $indexBase . '?page=home',
                'icon' => '⌂',
                'active' => $homeActive,
            ],
            [
                'label' => 'Calendario',
                'href' => $indexBase . '?page=calendario',
                'icon' => '▦',
                'active' => false,
            ],
        ];
    }
}
