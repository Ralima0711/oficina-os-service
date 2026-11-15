<?php

namespace Tests\Feature;

use App\Application\Messaging\MessageConsumer;
use App\Application\Messaging\MessagePublisher;
use ReflectionProperty;
use Tests\TestCase;

/**
 * Publicador e consumidor saem do container com a configuracao de
 * config/messaging.php — que e para onde o Deployment aponta via RABBITMQ_*.
 * E saem como singletons: o publicador guarda a conexao, e um por resolucao
 * abriria uma conexao AMQP por mensagem.
 *
 * Nenhum dos dois conecta no construtor, entao nada aqui toca o broker.
 */
class SagaServiceProviderTest extends TestCase
{
    private function config(): void
    {
        config(['messaging' => array_merge(config('messaging'), [
            'host' => 'rabbit.interno',
            'port' => 5673,
            'user' => 'oficina',
            'password' => 'segredo',
            'vhost' => '/oficina',
            'exchange' => 'oficina.eventos.teste',
        ])]);
    }

    private function ler(object $objeto, string $propriedade): mixed
    {
        $prop = new ReflectionProperty($objeto, $propriedade);
        $prop->setAccessible(true);

        return $prop->getValue($objeto);
    }

    public static function classes(): array
    {
        return [
            'publicador' => [MessagePublisher::class],
            'consumidor' => [MessageConsumer::class],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('classes')]
    public function test_sai_do_container_com_a_configuracao_de_messaging(string $classe): void
    {
        $this->config();

        $instancia = app($classe);

        $this->assertInstanceOf($classe, $instancia);
        $this->assertSame('rabbit.interno', $this->ler($instancia, 'host'));
        $this->assertSame(5673, $this->ler($instancia, 'port'));
        $this->assertSame('oficina', $this->ler($instancia, 'user'));
        $this->assertSame('segredo', $this->ler($instancia, 'password'));
        $this->assertSame('/oficina', $this->ler($instancia, 'vhost'));
        $this->assertSame('oficina.eventos.teste', $this->ler($instancia, 'exchange'));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('classes')]
    public function test_e_singleton(string $classe): void
    {
        $this->assertSame(app($classe), app($classe));
    }
}
