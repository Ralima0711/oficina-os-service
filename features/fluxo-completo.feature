# language: pt
@saga
Funcionalidade: Fluxo completo da ordem de serviço
  Da abertura pelo mecânico ao encerramento, pela saga orquestrada no
  OS Service (docs/saga-maquina-estados.md).

  Os eventos de Billing e Execução entram aqui como mensagens que o
  orquestrador consome — e a prova do que ele mandou fica no outbox, gravado
  na MESMA transação da mudança de estado. É esse o contrato que o
  SagaOutboxTest já valida em PHPUnit; aqui ele fica dito como comportamento,
  em cenário.

  Cenário: Abrir uma ordem de serviço
    Dado um cliente com um veículo cadastrados
    Quando eu abro uma ordem de serviço para esse veículo
    Então a resposta deve ter o código "201"
    E o status da ordem deve ser "RECEBIDA"

  Cenário: Concluir o diagnóstico inicia a saga
    Dado um cliente com um veículo cadastrados
    E uma ordem de serviço aberta para esse veículo
    Quando eu concluo o diagnóstico com itens de exemplo
    Então a resposta deve ter o código "202"
    E o status da ordem deve ser "AGUARDANDO_ORCAMENTO"
    E o outbox deve ter gravado o comando "GerarOrcamento" para "billing.gerar-orcamento"

  Cenário: Diagnóstico sem itens não abre a saga
    Dado um cliente com um veículo cadastrados
    E uma ordem de serviço aberta para esse veículo
    Quando eu tento concluir o diagnóstico sem itens
    Então a resposta deve ter o código "422"

  Cenário: Concluir o diagnóstico duas vezes é recusado pelo estado
    Dado um cliente com um veículo cadastrados
    E uma ordem de serviço aberta para esse veículo
    Quando eu concluo o diagnóstico com itens de exemplo
    E eu tento concluir o diagnóstico de novo
    Então a resposta deve ter o código "409"

  Cenário: O caminho feliz termina em FINALIZADA
    Dado uma saga iniciada
    Quando o orçamento é gerado no valor de "241.80"
    E o orçamento é aprovado
    E o pagamento é confirmado
    E a execução é concluída
    Então o status da ordem deve ser "FINALIZADA"
    E o outbox deve conter na ordem os tipos "GerarOrcamento, aprovacao.expirada, CobrarPagamento, EnviarParaExecucao, os.finalizada"

  Cenário: Falha na execução compensa na ordem inversa
    Dado uma saga iniciada
    Quando o orçamento é gerado no valor de "241.80"
    E o orçamento é aprovado
    E o pagamento é confirmado
    E a execução falha por "peca_indisponivel"
    Então o status da ordem deve ser "CANCELADA"
    E as compensações saem na ordem inversa "CancelarExecucao, EstornarPagamento, AnularOrcamento, os.cancelada"

  Cenário: Pagamento recusado anula o orçamento sem estornar
    Dado uma saga iniciada
    Quando o orçamento é gerado no valor de "241.80"
    E o orçamento é aprovado
    E o pagamento é recusado por "cc_rejected"
    Então o status da ordem deve ser "CANCELADA"
    E o outbox deve conter o tipo "AnularOrcamento"
    Mas o outbox não deve conter o tipo "EstornarPagamento"

  Cenário: Orçamento que falhou cancela sem compensar nada
    Dado uma saga iniciada
    Quando o orçamento falha por "sem_itens_a_orcar"
    Então o status da ordem deve ser "CANCELADA"
    E o outbox deve conter só "GerarOrcamento" e "os.cancelada"
