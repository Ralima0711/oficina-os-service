# Checklist de pendências — Fase 4 (Johny)

Estado verificado contra o código em `feat/read-model` (2b15e33) e contra o GitHub, em 12/11/2026.
Suíte local: 315 testes, 611 asserções, 43 pulados, OK.

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

- [ ] **Idempotência do read model no Mongo (apontada pelo Gustavo em 12/11)**
  `MongoRepositorioDeLeitura::registrarEvento` usa `updateOne` com `upsert: true` e filtro
  `{osId, messageIds: {$ne: messageId}}`. Na reentrega o filtro não casa e o upsert **insere** —
  o upsert não olha se o documento existe. Com o índice único em `osId` (criado no arranque do worker)
  isso vira erro 11000, que não é `LostUpdateException` nem `PDOException`, então o `MessageConsumer`
  manda para a DLQ em vez de tratar como já aplicada. Provado no Mongo real: 11000 na reentrega.
  Correção: `updateOne` sem upsert quando o documento existe; `insert` só quando não existe;
  `DuplicateKey` na corrida vira segunda tentativa do update.
  **O teste `testReentregaNaoDuplicaNoMongo` NÃO pega isso**: ele não chama `garantirIndices()`,
  então sem índice único a reentrega insere um segundo documento e a asserção olha só o primeiro.
  Verificado: o teste passa e deixa 2 documentos com o mesmo `osId` na coleção.

- [ ] **Bindings do read model no manifesto do cluster**
  `k8s/base/11-rabbitmq-definitions.yaml` liga `os.readmodel.eventos` só em `os.*`. Os bindings de
  `orcamento.*`, `pagamento.*`, `execucao.*` e `aprovacao.expirada` estão no `definitions.json` do
  compose, não no manifesto — no EKS o worker não vê o meio da saga. Correção no `oficina-infra-fase4`.

- [ ] **PDF da entrega** — nenhum `.pdf` no repo.
- [ ] **Vídeo** — adiado pela Roberta.
- [ ] **Análise no SonarQube** — a configuração existe, mas o resultado no servidor não dá para confirmar daqui.
- [ ] **Merge da pilha para a main** — #12 e #13 já entraram (main = 1d0b56b); o que resta é o PR desta branch.

## 4. Ordem sugerida

1. ~~`docs/openapi.yaml` — destrava também a collection do Postman.~~ ✅ 12/11
2. ~~BDD do fluxo completo.~~ ✅ 12/11
3. Fechar com a Roberta onde vive a API Composition.

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
