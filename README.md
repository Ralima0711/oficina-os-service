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
| `docs/contrato-eventos-fase4.md` | Contrato v1.0 — envelope, filas, saga e payloads. **Em caso de divergência, ele manda.** |
| `docs/adrs-fase4.md` | ADR-0007 a ADR-0012 |
| `docs/asyncapi.yaml` | Contrato AsyncAPI dos eventos |
| `docs/guia-testes-cobertura.md` | Pirâmide de testes e meta de 80% |

## Regras do repositório

- `main` protegida: PR obrigatório com 1 aprovação
- **PR sem teste não é aprovado**
- Meta de cobertura: **80%**, com quality gate no SonarQube

---

*Grupo 77 · FIAP SOAT Pós-Tech · Fase 4*
