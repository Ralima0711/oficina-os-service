# OS Service — API, consumidor da saga e publicador do outbox na mesma imagem.
#
# Os tres processos rodam do mesmo build; o que muda e o comando. Nos manifestos
# do oficina-infra-fase4 isso aparece como tres Deployments apontando para esta
# imagem com command diferente:
#
#   API                 php artisan serve
#   Consumidor da saga  php artisan saga:consumir
#   Publicador outbox   php artisan saga:publicar-outbox
#
# As tres extensoes abaixo nao vem habilitadas na imagem oficial do PHP e cada
# uma esta aqui por um motivo medido, nao por precaucao:
#
#   pdo_pgsql  o banco do servico e Postgres (ADR-0007)
#   bcmath     php-amqplib/php-amqplib v2.8.0 declara "ext-bcmath": "*" no
#              composer.lock. Sem ela o `composer install` deste Dockerfile
#              falha na checagem de plataforma e o build nao conclui.
#   pcntl      MessageConsumer e PublicarOutbox registram SIGTERM/SIGINT para
#              terminar a passada em curso em vez de morrer no meio. O codigo
#              esta guardado por function_exists(), entao sem pcntl ele nao
#              quebra — so nao faz nada, e o encerramento gracioso que o
#              Kubernetes espera no rollout vira um kill seco.
#
# ext-sockets NAO entra aqui: este servico abre a conexao com AMQPStreamConnection
# e o lock do amqplib 2.8 nao pede sockets. Quem pede e o billing e a execucao,
# que estao no amqplib 3.7.

FROM php:8.4-cli

RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev unzip \
    && docker-php-ext-install pdo_pgsql bcmath pcntl \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# As dependencias entram antes do codigo: enquanto composer.json e composer.lock
# nao mudarem, esta camada vem do cache e o build nao reinstala tudo a cada push.
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-scripts

COPY . .
RUN composer dump-autoload --optimize \
    && php artisan package:discover --ansi

EXPOSE 8080

CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8080"]
