<?php

namespace App\Providers;

use App\Application\Messaging\MessageConsumer;
use App\Application\Messaging\MessagePublisher;
use App\Application\Saga\Orchestrator;
use App\Domain\Cliente\Repositories\ClienteRepositoryInterface;
use App\Domain\OrdemServico\Repositories\OrdemServicoRepositoryInterface;
use App\Domain\Veiculo\Repositories\VeiculoRepositoryInterface;
use App\Infrastructure\Persistence\Cliente\EloquentClienteRepository;
use App\Infrastructure\Persistence\OrdemServico\EloquentOrdemServicoRepository;
use App\Infrastructure\Persistence\Veiculo\EloquentVeiculoRepository;
use Illuminate\Support\ServiceProvider;

class SagaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            OrdemServicoRepositoryInterface::class,
            EloquentOrdemServicoRepository::class,
        );

        $this->app->bind(
            ClienteRepositoryInterface::class,
            EloquentClienteRepository::class,
        );

        $this->app->bind(
            VeiculoRepositoryInterface::class,
            EloquentVeiculoRepository::class,
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

        $this->app->bind(
            \App\Application\ReadModel\RepositorioDeLeituraInterface::class,
            \App\Infrastructure\ReadModel\MongoRepositorioDeLeitura::class,
        );

        $this->app->singleton(\App\Application\ReadModel\ProjetorDeOrdens::class);
    }
}
