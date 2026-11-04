<?php

namespace App\Providers;

use App\Application\Messaging\MessageConsumer;
use App\Application\Messaging\MessagePublisher;
use App\Application\Saga\Orchestrator;
use App\Domain\OrdemServico\Repositories\OrdemServicoRepositoryInterface;
use App\Infrastructure\Persistence\OrdemServico\EloquentOrdemServicoRepository;
use Illuminate\Support\ServiceProvider;

class SagaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            OrdemServicoRepositoryInterface::class,
            EloquentOrdemServicoRepository::class,
        );

        $this->app->singleton(MessagePublisher::class, function ($app) {
            $c = $app['config']->get('messaging');
            return new MessagePublisher(
                $c['host'], $c['port'], $c['user'], $c['password'],
                $c['vhost'], $c['exchange'],
            );
        });

        $this->app->singleton(MessageConsumer::class, function ($app) {
            $c = $app['config']->get('messaging');
            return new MessageConsumer(
                $c['host'], $c['port'], $c['user'], $c['password'],
                $c['vhost'], $c['exchange'],
            );
        });

        $this->app->singleton(Orchestrator::class);
    }
}
