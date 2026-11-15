# oficina-os-service

Microsserviço de **Ordem de Serviço** do Tech Challenge SOAT Fase 4 — Grupo 77.
É o serviço de atendimento e **hospeda o orquestrador da Saga**.

## Responsabilidades

| Operação | Observação |
|---|---|
| Abrir OS | Dispara a Saga |
| Consultar status e histórico | Servido pelo read model |
| Cancelar OS | Também é destino das compensações |
| Cadastrar e consultar cliente e veículo | Domínio de atendimento |
| Autenticar cliente por CPF | JWT RS256 da Lambda, herdado da Fase 3 |
| Consulta agregada da OS | **API Composition** — junta o orçamento do Billing e a timeline da Execução |

### A consulta agregada, e por que ela não está no Kong

`GET /api/ordens-servico/{id}/completa` chama Billing e Execução por HTTP e devolve tudo
junto. É a **única** rota deste serviço que fala com os outros dois: a saga inteira é por
mensageria, e nada aqui dispara passo nenhum.

O Kong seria o lugar canônico do padrão, mas roda DB-less com a configuração versionada num
ConfigMap — agregar ali exigiria um plugin Lua próprio. O ganho seria o nome do componente;
o custo, uma peça nova em Lua. O OS Service já é dono da OS e já é quem responde "status e
histórico".

Duas propriedades que a tornam composição e não acoplamento:

- **Participante fora do ar não derruba a consulta.** O campo vem `null` e a resposta traz
  `parciais`, dizendo o que faltou. 200, não 5xx. Exigir os três de pé trocaria três
  disponibilidades independentes pela do pior deles.
- **Circuit Breaker** (Aula 2), por participante. Três falhas em um minuto abrem o circuito
  por trinta segundos, e nesse tempo as chamadas nem saem. Timeout cuida de uma chamada; o
  disjuntor cuida da enésima — e poupa quem caiu da carga de quem insiste.

## Bancos

| Banco | Papel |
|---|---|
| PostgreSQL | Escrita, estado da OS e estado da Saga |
| MongoDB | Read model de status e histórico — o CQRS do ADR-0009 |

Nenhum outro serviço acessa estes bancos.

## Estados da OS

```
RECEBIDA → EM_DIAGNOSTICO → AGUARDANDO_ORCAMENTO → AGUARDANDO_APROVACAO
        → APROVADA → AGUARDANDO_PAGAMENTO → EM_EXECUCAO → FINALIZADA → ENTREGUE

qualquer passo falha → CANCELADA
```

## Mensageria

**Publica comandos** em `billing.gerar-orcamento`, `billing.cobrar-pagamento`,
`billing.anular-orcamento`, `billing.estornar-pagamento`,
`execucao.enviar-para-execucao` e `execucao.cancelar-execucao`.

**Consome eventos** pela fila `os.saga.eventos`, ligada ao exchange `oficina.eventos`
(`orcamento.*`, `pagamento.*`, `execucao.*`, `aprovacao.*`).

Todo consumidor descarta `messageId` já processado — registro em tabela própria no Postgres.

## Como rodar o ambiente

A infraestrutura é compartilhada e vive em
[`oficina-infra-fase4`](https://github.com/Ralima0711/oficina-infra-fase4):

```bash
git clone https://github.com/Ralima0711/oficina-infra-fase4.git
cd oficina-infra-fase4 && docker compose up -d
```

## Documentação

| Documento | O que é |
|---|---|
| `docs/contrato-eventos-fase4.md` | Contrato v1.1 — envelope, filas, saga e payloads. **Em caso de divergência, ele manda.** |
| `docs/adrs-fase4.md` | ADR-0007 a ADR-0014 |
| `docs/asyncapi.yaml` | Contrato AsyncAPI dos eventos |
| `docs/guia-testes-cobertura.md` | Pirâmide de testes e meta de 80% |

## Regras do repositório

- `main` protegida: PR obrigatório com 1 aprovação
- **PR sem teste não é aprovado**

### Cobertura

[![Coverage](https://sonarcloud.io/api/project_badges/measure?project=Ralima0711_oficina-os-service&metric=coverage)](https://sonarcloud.io/summary/overall?id=Ralima0711_oficina-os-service)
[![Quality Gate](https://sonarcloud.io/api/project_badges/measure?project=Ralima0711_oficina-os-service&metric=alert_status)](https://sonarcloud.io/summary/overall?id=Ralima0711_oficina-os-service)

Meta do enunciado: **80% por serviço — atingida.**

| Medida | Valor | Evidência |
|---|---|---|
| Cobertura de linha medida pelo pipeline (pcov, `clover.xml`) | **93,68%** (1496 / 1597 instruções) | [execução do CI na `main`, 15/11/2026](https://github.com/Ralima0711/oficina-os-service/actions/runs/43708335855), job *Testes e cobertura* |
| Cobertura no SonarQube Cloud (linha + condição, só `app/`) | **80,7%** | [painel do projeto](https://sonarcloud.io/summary/overall?id=Ralima0711_oficina-os-service) |
| Gate do pipeline | **80%** | `cobertura-minima: 80` no `.github/workflows/ci.yml` |

O job *Testes e cobertura* reprova o PR abaixo de 80%, e o Sonar cobra 80% no código novo de cada PR. Em cada execução do CI, o artefato `cobertura-os-service` traz o `clover.xml` completo, arquivo por arquivo. A meta foi atingida só com testes: nada foi excluído da medição.

---

*Grupo 77 · FIAP SOAT Pós-Tech · Fase 4*
