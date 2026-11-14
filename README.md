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

A **meta** do enunciado é 80%. O que a suíte **mede** é **55,6%** (12/11, com pcov), e o
gate do pipeline está em **55** — no número medido, não no desejado.

Gate acima do que se mede não reprova nada: ou se desliga na primeira vez que incomoda, ou
se "atinge" excluindo arquivo do relatório. Os dois enganam. O gate está no medido para
reprovar de verdade quando a cobertura cair, e sobe junto com ela.

A distância para os 80% tem causa localizada: os domínios `Cliente` e `Veiculo` — 8
arquivos, com `Cpf`, `Cnpj`, `Placa` e `DocumentoFiscal` — vieram da decomposição da Fase 3
sem controller, sem repositório, sem rota e sem teste, e só foram ligados em 11/11.

No SonarQube Cloud a condição é **80% em New Code**, que é outra pergunta: não "quanto do
código inteiro está coberto", e sim "o que entrou neste PR veio coberto". Essa dá para
cobrar desde já, e é ela que faz a média subir sozinha.

---

*Grupo 77 · FIAP SOAT Pós-Tech · Fase 4*
