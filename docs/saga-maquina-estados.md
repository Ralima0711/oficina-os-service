# Máquina de estados da OS

> **Rascunho para revisão do grupo** · Tarefa 1 — Bloco 0  
> **Referências:** contrato de eventos v1.0 · ADR-0011

Este documento registra a revisão do contrato e uma proposta de regras para as transições, concorrência e idempotência da OS. **Não altera o contrato v1.0:** divergências precisam ser confirmadas pelo grupo e, se aprovadas, corrigidas na fonte oficial com incremento de versão.

## Índice

- [1. Revisão do contrato](#1-revisão-do-contrato)
- [2. Regras gerais](#2-regras-gerais-de-transição)
- [3. Tabela de transições](#3-tabela-de-transições)
- [4. Lock otimista](#4-concorrência-e-lock-otimista)
- [5. Idempotência](#5-idempotência-de-mensagens)
- [6. Decisões pendentes](#6-decisões-pendentes)

## 1. Revisão do contrato

O fluxo geral faz sentido: gerar orçamento, aguardar a decisão do cliente, cobrar antes da execução e compensar falhas posteriores. Estes pontos precisam de confirmação antes de fechar as regras de domínio.

### Pontos para alinhar

1. **Diagnóstico e itens do orçamento** — O contrato Markdown inclui `itensDiagnosticados` no exemplo de `GerarOrcamento`, mas o AsyncAPI não o marca como obrigatório. Nenhum dos documentos define claramente quem entrega os itens ao orquestrador ou um evento de diagnóstico concluído. Definir a origem, a obrigatoriedade e o payload.

2. **Recusa do cliente versus falha ao gerar orçamento** — No contrato Markdown, `orcamento.recusado` aparece como retorno da geração e como decisão do cliente. Já o AsyncAPI descreve `OrcamentoDecidido` como decisão do cliente e não define um evento de falha na geração. Alinhar as fontes e definir como representar cada resultado.

3. **Expiração em sete dias** — O contrato Markdown define TTL no RabbitMQ. O AsyncAPI já descreve o payload de `AprovacaoExpirada` e diz que ele é publicado quando expira a mensagem da fila `billing.aguardando-aprovacao`. Ainda falta descrever a topologia DLX/fila de destino e qual componente transforma a dead-letter no evento de domínio. A expiração deve ser ignorada se não corresponder ao orçamento vigente ou se a saga já tiver avançado.

4. **Compensação da execução** — A fila `execucao.cancelar-execucao` e o comando `CancelarExecucao` existem, mas a tabela da Saga não deixa claro quando usá-los. Definir se se aplicam a uma execução enfileirada, iniciada ou a ambas, e como se ordenam em relação ao estorno e à anulação do orçamento.

5. **Comandos e eventos citados sem definição completa** — A Parte 3b menciona `CancelarOS` e `os.finalizada`; a Parte 2 lista `os.cancelada`. Como a Parte 4 determina que campos não listados não existem, é preciso definir payloads e produtor/consumidor para esses itens ou remover as referências. O AsyncAPI já define o payload de `AprovacaoExpirada`.

6. **Estado `APROVADA`** — O fluxo inclui esse estado, mas não deixa explícito se ele é persistido antes de `AGUARDANDO_PAGAMENTO`, nem em que momento o comando de cobrança é publicado. Confirmar a sequência.

7. **Gatilhos de execução e finalização** — Definir se `pagamento.confirmado` só dispara `EnviarParaExecucao` ou também altera o estado, e se `execucao.iniciada` confirma a entrada em `EM_EXECUCAO`. Esclarecer também a relação entre `execucao.concluida`, `FINALIZADA` e `os.finalizada`.

8. **Falhas e eventos fora de ordem** — Idempotência por `messageId` evita a repetição da mesma mensagem, mas não resolve eventos atrasados, incompatíveis com a fase atual ou de outra saga. Definir registro, descarte/retry e política de DLQ.

9. **Cobrança duplicada** — A idempotência local não impede, por si só, chamadas duplicadas ao Mercado Pago. O Billing deve confirmar uma chave de idempotência externa e sua origem (por exemplo, um `messageId` estável do comando).

10. **Cancelamento solicitado pelo cliente** — `cancelado_pelo_cliente` aparece entre os motivos de compensação, mas não há operação, evento ou regra de elegibilidade definidos. Estabelecer a política e as compensações por estágio antes de expor esse cancelamento pela API.

> **Parecer provisório:** o contrato v1.0 continua sendo a referência vigente e está marcado como fechado para implementação. Os itens acima são ambiguidades identificadas nesta revisão, não alterações aprovadas. Validar Billing com Gustavo, diagnóstico/execução com David e decisões transversais com Roberta. Se o grupo confirmar mudanças, atualizar a fonte pertinente e incrementar sua versão conforme a regra do contrato.

## 2. Regras gerais de transição

1. Validar o estado persistido, `osId`, `sagaId` e, quando aplicável, o identificador do recurso vigente (`orcamentoId`, `pagamentoId` ou `execucaoId`).
2. Uma repetição do mesmo `messageId` não pode produzir efeitos adicionais. Mensagens diferentes que representem semanticamente o mesmo resultado também não podem avançar a Saga duas vezes.
3. Eventos atrasados, de outra saga/recurso ou incompatíveis com o estado atual não alteram a OS. Registrar a ocorrência e aplicar a política acordada de retry ou DLQ; nunca forçar a transição.
4. **`CANCELADA` e `ENTREGUE` são terminais.** Nenhum evento ou solicitação os altera; uma reabertura exigiria um fluxo explícito aprovado pelo grupo.
5. Falhas em etapas diferentes podem exigir compensações diferentes. “Qualquer falha → `CANCELADA`” não autoriza pular compensações. Durante a compensação, a fase da Saga deve impedir que eventos de sucesso concorrentes avancem o fluxo.
6. A tabela abaixo é uma **lista de permissões**: toda transição não listada é ilegal. Transições para `CANCELADA` continuam sujeitas às guardas e compensações específicas.

## 3. Tabela de transições

**Legenda:** `Condicional` significa que a transição só é permitida quando a guarda é satisfeita. Itens marcados **Pendente** dependem de confirmação do grupo.

| De | Para | Regra e guarda |
|---|---|---|
| `RECEBIDA` | `EM_DIAGNOSTICO` | **Condicional:** ação autorizada inicia o diagnóstico e o ator/mecânico é válido. Definir responsável e como o resultado chega à OS. |
| `EM_DIAGNOSTICO` | `AGUARDANDO_ORCAMENTO` | **Condicional · Pendente:** diagnóstico concluído e itens disponíveis. Origem e payload ainda precisam ser definidos. |
| `AGUARDANDO_ORCAMENTO` | `AGUARDANDO_APROVACAO` | **Condicional:** `orcamento.gerado` corresponde à OS e à saga ativa; registrar o `orcamentoId` vigente. |
| `AGUARDANDO_ORCAMENTO` | `CANCELADA` | **Condicional · Pendente:** falha definitiva de geração, após retries e compensações aplicáveis. Evento e payload de falha não estão definidos. |
| `AGUARDANDO_APROVACAO` | `APROVADA` | **Condicional:** `orcamento.aprovado` corresponde à OS, à saga ativa e ao `orcamentoId` vigente, dentro da validade. |
| `AGUARDANDO_APROVACAO` | `CANCELADA` | **Condicional:** recusa do cliente para o orçamento vigente ou expiração válida. Ignorar expiração tardia se a saga já avançou ou o orçamento não for o vigente. |
| `APROVADA` | `AGUARDANDO_PAGAMENTO` | **Condicional:** aprovação persistida; o orquestrador inicia a cobrança do orçamento aprovado. Confirmar estado intermediário e publicação confiável do comando. |
| `AGUARDANDO_PAGAMENTO` | `EM_EXECUCAO` | **Condicional · Pendente:** pagamento confirmado para o mesmo `osId` e `orcamentoId` permite enviar `EnviarParaExecucao`. Confirmar se o estado muda ao enviar o comando ou ao receber `execucao.iniciada`. |
| `AGUARDANDO_PAGAMENTO` | `CANCELADA` | **Condicional:** recusa ou falha/timeout definitivo, após definir compensações e garantir que não há cobrança confirmada. |
| `EM_EXECUCAO` | `FINALIZADA` | **Condicional:** `execucao.concluida` corresponde à execução vigente e à saga ativa; persistir uma única vez. Confirmar emissão de `os.finalizada`. |
| `EM_EXECUCAO` | `CANCELADA` | **Condicional:** falha definitiva e compensações aplicáveis concluídas ou registradas, incluindo estorno/anulação e, se decidido, cancelamento da execução. |
| `FINALIZADA` | `ENTREGUE` | **Condicional:** ação operacional autorizada, fora da Saga. Definir endpoint/ator e registrar histórico. |
| Estado elegível, a definir por estágio | `CANCELADA` | **Condicional · Pendente:** causa válida para o estágio e compensações exigidas. Definir estados elegíveis e política de cancelamento pelo cliente. `FINALIZADA`, `ENTREGUE` e `CANCELADA` não entram nesta regra genérica. |
| `CANCELADA` ou `ENTREGUE` | Qualquer outro estado | **Ilegal:** estados terminais; eventos atrasados não reabrem nem regridem a OS. |
| Qualquer estado | Estado que salte etapa obrigatória | **Ilegal:** por exemplo, `AGUARDANDO_APROVACAO` → `EM_EXECUCAO` sem aprovação e pagamento confirmados. |
| Qualquer estado | Mesmo estado | **Sem transição:** tratar como repetição idempotente; não duplicar efeitos ou comandos. |

### Diferenças em relação à Fase 3

A entidade da Fase 3 permite `AGUARDANDO_APROVACAO → APROVADA → EM_EXECUCAO`; a recusa (`reprovar`) termina em `FINALIZADA`. Na Fase 4, o pagamento passa a ser pré-requisito da execução e recusa/expiração devem seguir a política de cancelamento e compensação aprovada. Não copiar essas regras sem adaptação.

## 4. Concorrência e lock otimista

A OS é o agregado. O lock deve ficar no repositório PostgreSQL do OS Service, na operação transacional que consome o evento e aplica a transição. Adicionar à tabela da OS uma coluna `version` inteira, inicializada em `1`.

A atualização compara a versão lida e incrementa atomicamente:

```sql
UPDATE ordens_servico
SET status = :novo_status,
    version = version + 1,
    updated_at = NOW()
WHERE id = :os_id
  AND version = :versao_lida
  AND status = :status_esperado;
```

- **Uma linha atualizada:** a transição venceu. Persistir também a etapa da Saga e o registro de idempotência na mesma transação.
- **Nenhuma linha atualizada:** houve concorrência ou a guarda deixou de valer. Reler o agregado e revalidar a mensagem; não sobrescrever o estado atual. Tentar novamente com limite definido ou encaminhar conforme a política de retry/DLQ.
- Não manter a transação do banco aberta durante chamadas ao RabbitMQ ou a APIs externas. A publicação confiável requer uma decisão de implementação, como uma outbox transacional, a ser registrada no desenho técnico.

## 5. Idempotência de mensagens

O OS Service guarda os `messageId` processados em tabela própria no PostgreSQL, por exemplo:

```text
mensagens_processadas
├── message_id UUID PRIMARY KEY
├── saga_id UUID NOT NULL
├── tipo VARCHAR NOT NULL
└── processado_em TIMESTAMP NOT NULL
```

### Fluxo de consumo proposto

1. Iniciar uma transação e inserir o `messageId` com restrição única. Se já existir um ID confirmado, descartar como duplicata sem repetir efeitos.
2. Validar saga, estado e identificadores vigentes; aplicar a transição com controle de `version`.
3. Persistir estado do agregado, etapa da Saga e intenção de saída (por exemplo, em uma outbox) na mesma transação.
4. Confirmar a transação e só então enviar ACK ao RabbitMQ.

### Falhas e retry

Erros transitórios, indisponibilidade do banco ou colisões de versão que exijam reavaliação devem causar **rollback**, inclusive da inserção do `messageId`, para permitir reprocessamento. Após reler o agregado, revalidar antes de tentar novamente.

Mensagens inválidas/obsoletas devem seguir a política acordada: se forem descartadas definitivamente, registrar essa disposição e confirmar sem aplicar efeitos; se exigirem retry/DLQ, não deixar um marcador confirmado que faça uma tentativa futura ser descartada como duplicata. A disposição deve ser auditável.

> **Pendente:** definir quais erros permitem retry, quais são descarte permanente/DLQ e como alinhar ACK/NACK com o commit local.

## 6. Decisões pendentes

- [ ] Definir evento, payload e origem dos itens de diagnóstico enviados ao orquestrador.
- [ ] Diferenciar falha na geração do orçamento de recusa do cliente.
- [ ] Definir topologia TTL/DLX e qual componente publica `aprovacao.expirada` após a expiração.
- [ ] Especificar payloads e uso de `CancelarOS`, `CancelarExecucao` e `os.finalizada`.
- [ ] Confirmar os gatilhos de aprovação, pagamento, início e conclusão da execução.
- [ ] Definir timeouts/retries por etapa, política para eventos inválidos/DLQ e quando confirmar `messageId` descartados permanentemente.
- [ ] Definir como a fase de compensação bloqueia eventos de sucesso concorrentes e quando a OS passa para `CANCELADA`.
- [ ] Definir política de cancelamento pelo cliente, estados elegíveis e idempotência externa da cobrança.
- [ ] Validar as guardas com Roberta, Gustavo e David antes de aprovar o documento.
