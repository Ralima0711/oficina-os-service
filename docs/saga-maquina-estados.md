# Máquina de estados da OS

> **Tarefa 1 — Bloco 0** · Referências: contrato de eventos v1.1 (PR #3) · ADR-0011

Este documento define as transições da OS, a concorrência e a idempotência no OS Service. A **tabela da seção 3 é a especificação dos testes do próximo PR**: um teste por transição legal e um por transição ilegal.

## Índice

- [1. Revisão do contrato](#1-revisão-do-contrato)
- [2. Regras gerais](#2-regras-gerais-de-transição)
- [3. Tabela de transições](#3-tabela-de-transições)
- [4. Lock otimista (Reread Value)](#4-concorrência-e-lock-otimista-reread-value)
- [5. Idempotência](#5-idempotência-de-mensagens)
- [6. Pendências de implementação](#6-pendências-de-implementação)

## 1. Revisão do contrato

Os 10 pontos levantados na v1.0 foram respondidos na v1.1 do contrato (PR #3). A tabela abaixo registra a resposta e onde ela vale nesta máquina de estados.

| # | Ponto | Resposta na v1.1 | Efeito na OS |
|---|---|---|---|
| 1 | Itens do orçamento | `itensDiagnosticados` obrigatório, mínimo 1. Vazio vira `orcamento.falhou` (`sem_itens_a_orcar`) | T2, T4 |
| 2 | Recusa × falha na geração | Eventos separados: `orcamento.falhou` (geração) e `orcamento.recusado` (cliente) | T4, T6 |
| 3 | Expiração em 7 dias | TTL na fila `billing.aguardando-aprovacao`, DLX `oficina.espera`, fila `billing.aprovacao-expirada`. O Billing transforma a dead-letter em `aprovacao.expirada` | T6 |
| 4 | Compensação da execução | `CancelarExecucao` vale para execução enfileirada ou iniciada. Ordem: `CancelarExecucao` → `EstornarPagamento` → `AnularOrcamento` → `CancelarOS` | T11 |
| 5 | `CancelarOS`, `os.cancelada`, `os.finalizada` | `CancelarOS` é interno, sem fila. `os.cancelada` e `os.finalizada` têm payload definido | T10, T11 |
| 6 | Estado `APROVADA` | Persistido ao processar `orcamento.aprovado`, antes de publicar `CobrarPagamento` | T5, T7 |
| 7 | Gatilhos de execução | `EM_EXECUCAO` ao publicar `EnviarParaExecucao`. `execucao.iniciada` só registra `mecanicoId` e `execucaoId` | T8 |
| 8 | Eventos fora de ordem | Descartar, registrar em log e confirmar (ACK) se saga, estado ou recurso não conferem | Regra 3 |
| 9 | Cobrança duplicada | Restrição única por `orcamentoId` no Billing | fora da OS |
| 10 | Cancelamento pelo cliente | Fora do escopo da Fase 4. `cancelado_pelo_cliente` saiu do enum | sem transição |

## 2. Regras gerais de transição

1. Validar o estado persistido, `osId`, `sagaId` e o recurso vigente (`orcamentoId`, `pagamentoId` ou `execucaoId`).
2. Repetição do mesmo `messageId` não produz efeito adicional (seção 5).
3. Evento atrasado, de outra saga ou incompatível com o estado atual **não altera a OS**: registrar em log com o motivo e confirmar a mensagem. Não vai para a DLQ e não força transição.
4. **`CANCELADA` e `ENTREGUE` são terminais.** Nenhum evento os altera.
5. A tabela da seção 3 é uma **lista de permissões**: toda transição não listada é ilegal.
6. Durante a compensação, a OS só chega a `CANCELADA` depois do último passo (`CancelarOS`). Eventos de sucesso que chegarem nesse intervalo são descartados pela regra 3.
7. A transição de estado e a gravação do `version` acontecem na mesma operação (seção 4).

## 3. Tabela de transições

**Decisão do grupo:** estas são as únicas transições legais da OS. Cada linha `T` e cada linha `I` vira um teste.

### Transições legais (12 testes)

| ID | De | Para | Gatilho | Guarda |
|---|---|---|---|---|
| T1 | `RECEBIDA` | `EM_DIAGNOSTICO` | Ação autorizada de iniciar diagnóstico | Mecânico válido |
| T2 | `EM_DIAGNOSTICO` | `AGUARDANDO_ORCAMENTO` | Diagnóstico concluído; publica `GerarOrcamento` | Pelo menos 1 item em `itensDiagnosticados` |
| T3 | `AGUARDANDO_ORCAMENTO` | `AGUARDANDO_APROVACAO` | `orcamento.gerado` | `osId` e `sagaId` conferem; grava `orcamentoId` vigente |
| T4 | `AGUARDANDO_ORCAMENTO` | `CANCELADA` | `orcamento.falhou` | `osId` e `sagaId` conferem; compensação: só `CancelarOS` |
| T5 | `AGUARDANDO_APROVACAO` | `APROVADA` | `orcamento.aprovado` | `orcamentoId` é o vigente |
| T6 | `AGUARDANDO_APROVACAO` | `CANCELADA` | `orcamento.recusado` ou `aprovacao.expirada` | `orcamentoId` é o vigente; compensação: `AnularOrcamento` → `CancelarOS` |
| T7 | `APROVADA` | `AGUARDANDO_PAGAMENTO` | `CobrarPagamento` publicado | `APROVADA` já persistido |
| T8 | `AGUARDANDO_PAGAMENTO` | `EM_EXECUCAO` | `pagamento.confirmado`; publica `EnviarParaExecucao` | `osId` e `orcamentoId` conferem |
| T9 | `AGUARDANDO_PAGAMENTO` | `CANCELADA` | `pagamento.recusado` (inclui motivo `timeout`) | Compensação: `AnularOrcamento` → `CancelarOS` |
| T10 | `EM_EXECUCAO` | `FINALIZADA` | `execucao.concluida` | `execucaoId` é o vigente; publica `os.finalizada` |
| T11 | `EM_EXECUCAO` | `CANCELADA` | `execucao.falhou` | Compensação: `CancelarExecucao` → `EstornarPagamento` → `AnularOrcamento` → `CancelarOS`; publica `os.cancelada` |
| T12 | `FINALIZADA` | `ENTREGUE` | Ação operacional autorizada, fora da saga | Ator autorizado; registra histórico |

Compensação que não se aplica é pulada, não é erro. `EstornarPagamento` só roda se houve pagamento confirmado.

### Transições ilegais (78 testes)

Os 10 estados formam 100 pares. Tirando as 12 legais e os 10 pares "mesmo estado", restam **78 transições ilegais**. O teste é parametrizado sobre esse conjunto e espera rejeição, sem mudar o estado nem o `version`.

| ID | De | Destinos ilegais | Pares |
|---|---|---|---|
| I1 | `RECEBIDA` | todos, exceto `EM_DIAGNOSTICO` (inclui `CANCELADA`) | 8 |
| I2 | `EM_DIAGNOSTICO` | todos, exceto `AGUARDANDO_ORCAMENTO` (inclui `CANCELADA`) | 8 |
| I3 | `AGUARDANDO_ORCAMENTO` | todos, exceto `AGUARDANDO_APROVACAO` e `CANCELADA` | 7 |
| I4 | `AGUARDANDO_APROVACAO` | todos, exceto `APROVADA` e `CANCELADA` (inclui `EM_EXECUCAO` sem pagamento) | 7 |
| I5 | `APROVADA` | todos, exceto `AGUARDANDO_PAGAMENTO` (inclui `CANCELADA`) | 8 |
| I6 | `AGUARDANDO_PAGAMENTO` | todos, exceto `EM_EXECUCAO` e `CANCELADA` | 7 |
| I7 | `EM_EXECUCAO` | todos, exceto `FINALIZADA` e `CANCELADA` | 7 |
| I8 | `FINALIZADA` | todos, exceto `ENTREGUE` (inclui `CANCELADA`) | 8 |
| I9 | `CANCELADA` | qualquer outro estado (terminal) | 9 |
| I10 | `ENTREGUE` | qualquer outro estado (terminal) | 9 |

Soma: 8+8+7+7+8+7+7+8+9+9 = 78. A implementação deve gerar o conjunto como `todos os pares − legais − mesmo estado`, sem digitar os pares à mão, e conferir que ele tem 78 elementos.

### Mesmo estado (10 testes)

Evento que levaria a OS ao estado em que ela já está é repetição idempotente: não muda estado, não incrementa `version`, não publica comando de novo.

### Diferenças em relação à Fase 3

Na Fase 3, `AGUARDANDO_APROVACAO → APROVADA → EM_EXECUCAO` e a recusa termina em `FINALIZADA`. Na Fase 4 o pagamento é pré-requisito da execução e recusa ou expiração levam a `CANCELADA` com compensação. Não copiar as regras da Fase 3 sem adaptar.

## 4. Concorrência e lock otimista (Reread Value)

O lock otimista da OS é o padrão **Reread Value** do ADR-0011: reler o valor antes de atualizar e abortar se a versão mudou, o que evita a atualização perdida (*lost update*). A OS é o agregado e a coluna `version` (inteira, inicial `1`) fica na tabela da OS, no PostgreSQL do OS Service.

```sql
UPDATE ordens_servico
SET status = :novo_status,
    version = version + 1,
    updated_at = NOW()
WHERE id = :os_id
  AND version = :versao_lida
  AND status = :status_esperado;
```

- **Uma linha atualizada:** a transição venceu. Gravar a etapa da saga e o registro de idempotência na mesma transação.
- **Nenhuma linha atualizada:** outro consumidor alterou a OS. Reler o agregado e revalidar a mensagem pela regra 3. Não sobrescrever. Repetir com limite de tentativas.
- Não manter a transação aberta durante chamada ao RabbitMQ ou API externa. Publicação confiável por outbox transacional (ver ADR-0011).

## 5. Idempotência de mensagens

Segue a regra transacional do contrato v1.1 para serviços Postgres: o `messageId` é inserido **dentro** da transação que aplica os efeitos.

```text
mensagens_processadas
├── message_id UUID PRIMARY KEY
├── saga_id UUID NOT NULL
├── tipo VARCHAR NOT NULL
└── processado_em TIMESTAMP NOT NULL
```

### Fluxo de consumo

1. Abrir transação e inserir o `messageId`. Se já existir, descartar como duplicata e confirmar a mensagem.
2. Validar saga, estado e recurso vigente (regra 1) e aplicar a transição com `version` (seção 4).
3. Gravar estado, etapa da saga e intenção de saída (outbox) na mesma transação.
4. Fazer commit e só então enviar ACK.

### Falhas

- **Erro transitório** (banco fora, colisão de versão): rollback, inclusive do `messageId`, e novo processamento.
- **Mensagem obsoleta ou de outra saga:** descartar, registrar em log e confirmar (regra 3).
- **Campo acessório inválido:** corrigir e registrar em log. **Campo essencial inválido:** publicar evento de falha do passo.
- **DLQ:** só para mensagem que não pode ser interpretada.

## 6. Pendências de implementação

Não bloqueiam a tabela. Ficam para o PR de código.

- [ ] Endpoint e ator da ação de concluir diagnóstico (T2) e de entregar (T12).
- [ ] Limite e intervalo de retries na colisão de `version`.
- [ ] Desenho da outbox (ADR-0011).
