<?php
declare(strict_types=1);

namespace VazinCMS\Contracts;

interface AuthProviderInterface
{
    public function key(): string;

    public function label(): string;

    public function isConfigured(): bool;

    public function authorizationUrl(string $state, string $redirectUri): string;

    /**
     * @param array<string,mixed> $query
     * @return array{external_id:string,email:string,name:string,locale?:string,verified:bool}
     */
    public function authenticateCallback(array $query): array;
}
