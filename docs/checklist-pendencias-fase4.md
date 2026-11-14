# Checklist de pendências — Fase 4 (Johny)

Estado verificado contra a `main` (1d0b56b, com #12 e #13 mergeados) e contra o GitHub, em 12/11/2026.
Suíte local: 319 testes, 627 asserções, 43 pulados, OK. Com o fix do #15 no container: 320 testes, 641 asserções, 39 pulados.

## 1. No meu nome

- [x] **Compensações — cancelar OS, anular orçamento**
  `app/Application/Saga/Orchestrator.php:382-388` encadeia CancelarExecucao → EstornarPagamento → AnularOrcamento → CANCELADA.
  Coberto por `tests/Feature/SagaOutboxTest.php:393` (ordem inversa), `:428` (pagamento recusado anula sem estornar) e `:458` (orçamento falhou não compensa nada).

- [x] **OS Service — Postgres, abertura de OS, máquina de estado persistida, REST + Swagger**
  Postgres, abertura, máquina de estado e REST prontos: `routes/api.php:30-52`.
  **Swagger/OpenAPI feito (12/11):** `docs/openapi.yaml` (OpenAPI 3.0.3, 8 rotas).
  **Collection Postman feita:** `docs/postman/oficina-os-service.postman_collection.json`.

- [x] **BDD do fluxo completo verde**
  **Feito (12/11):** Behat 4 (família Cucumber no PHP), 16 cenários / 72 steps verdes contra Postgres real.
  `features/fluxo-completo.feature` (saga: caminho feliz, compensações, recusas) e
  `features/clientes-veiculos.feature` (CRUD). Runner: `vendor/bin/behat` — os cenários `@saga`
  exigem Postgres em banco `*_test` (mesma guarda do `ExigeBancoDeTeste`; o FeatureContext recusa o resto).
  CI: job `bdd` em `.github/workflows/ci.yml` com Postgres de serviço.

- [x] **Trace distribuído — SDK do OpenTelemetry no os-service (apontado pelo David em 13/11)**
  O serviço repassava o `traceparent` no envelope e nos headers AMQP, mas não gerava span nenhum:
  no New Relic aparecia só o pedaço da Execução e o trace não atravessava os três serviços.
  Agora tem `open-telemetry/sdk` + `exporter-otlp`, span SERVER no middleware HTTP, span PRODUCER
  no `MessagePublisher` e CONSUMER no `MessageConsumer` (filho do `traceparent` recebido), e log
  JSON no stdout com `service.name` e `trace.id` injetados por processor. Sem
  `OTEL_EXPORTER_OTLP_ENDPOINT` o provider é no-op e nada quebra — o infra já entrega o endpoint e
  `OTEL_SERVICE_NAME=oficina-os-service` nos manifestos (PR #9 do infra).

- [ ] **Consulta agregada da OS via API Composition no Kong**
  `k8s/base/20-kong.yaml` (infra) só tem rota por serviço: `os-ordens-servico`, `os-clientes`, `os-veiculos`, billing, execucao. Nenhuma rota agregada.
  Depende de decisão da Roberta: rota composta no Kong ou BFF no os-service.

## 2. De todos — o que já dá para marcar

- [x] ADRs — `docs/adrs-fase4.md`
- [x] Contrato de eventos — `docs/contrato-eventos-fase4.md`
- [x] AsyncAPI — `docs/asyncapi.yaml`
- [x] Diagrama de arquitetura — `docs/arquitetura-fase4.png`
- [x] README — `README.md`
- [x] CI — `.github/workflows/ci.yml`
- [x] Configuração do Sonar — `sonar-project.properties`
- [x] Dockerfile — `Dockerfile` (imagem `os-service:test` com ext-mongodb construída)
- [x] Read model Mongo (CQRS) — commits 56b59cc e 2b15e33, **PR #12 mergeado na main** (1d0b56b), junto com o #13 (3528800). Integração 4/4 no container.

## 3. De todos — o que ainda não fecha

- [x] **Idempotência do read model no Mongo (apontada pelo Gustavo em 12/11)**
  `MongoRepositorioDeLeitura::registrarEvento` usava `updateOne` com `upsert: true` e filtro
  `{osId, messageIds: {$ne: messageId}}`. Na reentrega o filtro não casa e o upsert **insere** —
  o upsert não olha se o documento existe. Com o índice único em `osId` (criado no arranque do worker)
  isso virava erro 11000, que não é `LostUpdateException` nem `PDOException`, então o `MessageConsumer`
  mandava para a DLQ em vez de tratar como já aplicada. Provado no Mongo real: 11000 na reentrega.
  **Corrigido no PR #15** (`fix/read-model-idempotencia`, commit 28e3faa): `updateOne` sem upsert quando
  o documento existe; `insert` só quando não existe; `DuplicateKey` na corrida vira segunda tentativa
  do update, que não casa e não faz nada.
  **O teste `testReentregaNaoDuplicaNoMongo` não pegava**: ele não chamava `garantirIndices()`, então
  sem índice único a reentrega inseria um segundo documento e a asserção olhava só o primeiro.
  Agora cria o índice e conta os documentos; `testReentregaConcorrenteNaoEstoura` cobre a corrida.
  Verificado contra o código antigo: os dois testes falham com E11000. Com o fix: 320 testes, 641
  asserções, 39 pulados, OK.

- [ ] **Bindings do read model no manifesto do cluster**
  `k8s/base/11-rabbitmq-definitions.yaml` liga `os.readmodel.eventos` só em `os.*`. Os bindings de
  `orcamento.*`, `pagamento.*`, `execucao.*` e `aprovacao.expirada` estão no `definitions.json` do
  compose, não no manifesto — no EKS o worker não vê o meio da saga. Correção no `oficina-infra-fase4`.

- [ ] **PDF da entrega** — nenhum `.pdf` no repo. Destravado: o read model já está na `main`.
- [ ] **Vídeo** — adiado pela Roberta.
- [ ] **Análise no SonarQube rodando** — a chave foi preenchida no `ci.yml`, mas o job só executa com
  `SONAR_TOKEN` nos secrets. Sem o token, continua pulado.
- [x] **Merge da pilha para a main** — #8 a #13 todos mergeados; `main` = 1d0b56b, com o conteúdo
  de `feat/dockerfile`, `fix/caminho-de-escrita`, `feat/api-ordens-servico`, `feat/cliente-e-veiculo`,
  `fix/prazo-de-aprovacao`, `feat/read-model` e `fix/read-model-ligado`. Restam só os PRs #14 e #15.
- [x] **Sonar no CI** — `sonar-project-key` estava vazio no `ci.yml` e o job era pulado inteiro.
  Preenchido com `Ralima0711_oficina-os-service`, a mesma chave de `sonar-project.properties`.
  O job agora roda e falha no step "Analisar": falta o `SONAR_TOKEN`. Criar o projeto no SonarCloud,
  cadastrar o token e a organização é a tarefa 66 do board, **da Roberta** — não minha.

## 4. Ordem sugerida

1. ~~`docs/openapi.yaml` — destrava também a collection do Postman.~~ ✅ 12/11
2. ~~BDD do fluxo completo.~~ ✅ 12/11
3. ~~Idempotência do read model~~ ✅ PR #15
4. ~~Sonar no CI~~ ✅ 12/11 (falta o `SONAR_TOKEN`)
5. Fechar com a Roberta onde vive a API Composition.
6. PDF da entrega — agora destravado.

## 5. Como rodar o que foi feito

- Swagger: abrir `docs/openapi.yaml` no editor/Swagger UI (8 rotas, 3.0.3).
- Postman: importar `docs/postman/oficina-os-service.postman_collection.json` — as variáveis
  `clienteId`, `veiculoId`, `osId` e `sagaId` se preenchem sozinhas na ordem dos requests.
- BDD (com o Postgres de teste no ar — container `os-pg-test`, porta 5433):

  ```
  DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5433 DB_DATABASE=oficina_os_test \
  DB_USERNAME=postgres DB_PASSWORD=postgres vendor/bin/behat
  ```

  Sem Postgres: `vendor/bin/behat --tags='~@saga'` (só o CRUD de clientes e veículos).

## 6. Board do Notion — o que é meu (export de 12/11)

Dez tarefas com meu nome. Cinco concluídas, cinco em aberto:

| # | Tarefa | Board | Realidade |
|---|---|---|---|
| 6 | OS Service — Postgres, abertura, máquina de estado, REST + Swagger | A fazer | ✅ PR #14 |
| 34 | Compensações — cancelar OS, anular orçamento | A fazer | ✅ código e testes (`Orchestrator.php:382-388`) |
| 43 | BDD do fluxo completo verde | A fazer | ✅ PR #14, 16 cenários |
| 44 | Gravar — fluxo completo da OS + saga e tratamento de falhas | A fazer | ⏳ adiado pela Roberta |
| 58 | Consulta agregada da OS via API Composition no Kong | A fazer | ⏳ depende da Roberta |
| 13, 26, 36, 45, 64, 67 | Orquestrador, máquina de estados, Reread Value, read model, Aggregate, testes de transição | Concluído | ✅ |

O board já diz, na tarefa 58, que "a aula prefere isso a mais uma projeção" — ou seja, a
direção é API Composition no Kong, não uma segunda projeção. O que falta decidir é quem
escreve o bloco no `20-kong.yaml`.

### Não é meu, mas trava a entrega

- **66** — criar os projetos no Sonar, cadastrar `SONAR_TOKEN` e `SONAR_ORGANIZATION` nos 4 repos: **Roberta**.
  É a causa da falha do job `sonar` no PR #14.
- **46** — PDF da entrega: **Roberta**.
- **76** — RMs dos quatro integrantes, **BLOQUEANTE para o PDF**: **Todos**. O meu RM **já foi enviado**
  (12/11); o board ainda mostra a tarefa em aberto, então falta atualizar o card, não enviar o dado.
- **47** — Swagger/collection Postman nos 3 repositórios: **Todos**. O os-service está feito (PR #14);
  billing (Gustavo) e execução (David) seguem pendentes.
- **50** — setup do BDD nos 3 repositórios: **David**, em revisão.

### Inconsistência no board

A tarefa 29 ("DECIDIR ferramenta de BDD — assistir Aulas 3 e 4") está como *A fazer*, e a 63
("BDD DECIDIDO: cenários em Gherkin, executados com Behat") está como *Concluído*. A 29 deveria
estar concluída também — a decisão já foi tomada e o Behat já roda no os-service.
