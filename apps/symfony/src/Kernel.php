<?php

declare(strict_types=1);

namespace SocialNetwork\SymfonyApp;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

final class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function getProjectDir(): string
    {
        return dirname(__DIR__);
    }

    public function getCacheDir(): string
    {
        return $this->getVarDir() . '/cache/' . $this->environment;
    }

    public function getLogDir(): string
    {
        return $this->getVarDir() . '/log';
    }

    /**
     * Кэш и логи пишутся вне проекта (APP_VAR_DIR, в контейнере это каталог www-data),
     * чтобы не зависеть от прав на bind mount и не смешивать кэш с кодом.
     */
    private function getVarDir(): string
    {
        $varDir = $_SERVER['APP_VAR_DIR'] ?? null;

        return is_string($varDir) && $varDir !== '' ? $varDir : $this->getProjectDir() . '/var';
    }
}
