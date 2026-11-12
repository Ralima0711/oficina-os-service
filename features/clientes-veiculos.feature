# language: pt
Funcionalidade: Clientes e veículos do OS Service
  O ADR-0007 decidiu em 20/10 que cliente e veículo ficam no OS Service, e
  não num quarto serviço. Estas regras valem sem saga e sem mensagem: são
  CRUD na borda HTTP.

  Cenário: Cadastrar um cliente
    Quando eu cadastro o cliente "Johny Oliveira" com o documento "123.456.789-09"
    Então a resposta deve ter o código "201"
    E o documento salvo é "123.456.789-09"

  Cenário: O mesmo documento não vira dois clientes
    Quando eu cadastro o cliente "Johny Oliveira" com o documento "123.456.789-09"
    E eu tento cadastrar "Outro Nome" com o mesmo documento
    Então a resposta deve ter o código "409"

  Cenário: Documento com dígito verificador inválido é recusado
    Quando eu cadastro o cliente "Impostor" com o documento "111.222.333-44"
    Então a resposta deve ter o código "422"

  Cenário: Atualizar o cliente
    Quando eu cadastro o cliente "Johny Oliveira" com o documento "123.456.789-09"
    E eu atualizo o telefone para "11987650000"
    Então a resposta deve ter o código "200"
    E o telefone salvo é "11987650000"

  Cenário: Cadastrar o veículo de um cliente
    Dado um cliente cadastrado
    Quando eu cadastro um veículo com a placa "DEM1A23"
    Então a resposta deve ter o código "201"
    E o veículo pertence ao cliente cadastrado

  Cenário: A mesma placa não vira dois veículos
    Dado um cliente cadastrado
    E um veículo com a placa "DEM1A23"
    Quando eu tento cadastrar um veículo com a placa "DEM1A23" de novo
    Então a resposta deve ter o código "409"

  Cenário: Veículo sem cliente existente é recusado
    Dado que não existe cliente com o id "00000000-0000-4000-8000-000000000000"
    Quando eu cadastro um veículo para esse cliente com a placa "ABC1D23"
    Então a resposta deve ter o código "422"

  Cenário: Listar os veículos de um cliente
    Dado um cliente cadastrado
    E um veículo com a placa "DEM1A23"
    Quando eu listo os veículos desse cliente
    Então a lista tem 1 veículo
    E a placa listada é "DEM1A23"
