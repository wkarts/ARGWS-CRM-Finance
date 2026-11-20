# Consulta de CNPJ e CEP

## Uso

Os formulários apresentam somente o botão **Consultar**. O usuário informa o CNPJ ou CEP, aciona a consulta e revisa os campos preenchidos. Não há lista de tecnologias, provedor, token ou parâmetro de integração.

Disponível no CNPJ do emitente e no CNPJ/CEP do tomador cadastrado ou do rascunho. O endereço do próprio cliente continua vindo do cadastro nativo; não foi criado um cadastro paralelo.

Nos parâmetros do emitente, clicar em Consultar atualiza os dados retornados, mesmo quando os campos já estavam preenchidos pelo cadastro da empresa. Nos cadastros de tomadores e no rascunho, a consulta completa apenas campos vazios. Em todos os formulários, preserva o que for digitado durante a requisição. Se o CNPJ/CEP ou o destinatário do formulário mudar antes da resposta, os dados anteriores não são aplicados. Consultar não salva o cadastro e não emite NFS-e.

O retorno pode incluir razão social, contato, endereço, código IBGE do município e inscrição municipal quando informada expressamente. Inscrição estadual não é utilizada como inscrição municipal. Na ausência de IM, a consulta do emitente orienta a conferência no cadastro da prefeitura. Apenas os campos existentes no respectivo formulário são utilizados. A consulta de CEP não altera identidade, número do imóvel ou complemento particular. Serviço, descrição da prestação e tributação nunca são inferidos a partir do CNAE.

## Integração técnica

`RegistrationLookup::current()->lookup('cnpj'|'cep', $value)` resolve o contexto no servidor. Reutiliza `Multiempresa\Application\Lookups` nas empresas autenticadas e na principal quando o contexto correspondente está disponível. Uma negativa da integração não é contornada por outra fonte.

Em instalação autônoma, a consulta de CNPJ usa BrasilAPI e CNPJ.ws como alternativa; CEP usa ViaCEP e BrasilAPI. A integração Multiempresa conserva as fontes e limites definidos pelo próprio módulo, inclusive ReceitaWS quando previsto por ele. Não exige Composer ou credenciais adicionais.

O endpoint `POST admin/nfse_nacional/lookup/cnpj` ou `/cep` recebe apenas `value` e o CSRF nativo. Exige sessão e permissão fiscal de editar ou configurar. Retorna `ok`, `message`, `data.fields` e o token CSRF atual. Não aceita empresa, URL, API ou caminho de arquivo fornecidos pelo cliente.

As consultas usam HTTPS com validação TLS, destinos fixos, ausência de redirecionamento, limite de resposta e validação da identidade retornada. CNPJ/CEP inválidos são recusados antes da chamada externa. O CNPJ alfanumérico é validado pelo mesmo validador do módulo; a existência dos dados depende da cobertura da fonte pública.

O fallback próprio tem até 4 segundos por tentativa e orçamento de 8 segundos por consulta. Há cache isolado por empresa: até 6 horas para resposta completa, 5 minutos para parcial e 30 segundos para indisponibilidade. O armazenamento fica no diretório de cache do CRM, em arquivo protegido contra execução/leitura HTTP como conteúdo de dados. O limite de uso é 20 consultas por minuto por funcionário; CNPJ.ws tem limite próprio de 3 chamadas por minuto. O serviço reaproveitado do Multiempresa mantém seus próprios controles.

O servidor precisa de saída HTTPS para as fontes utilizadas e de escrita no diretório de cache do CRM. Indisponibilidade ou limitação externa permite continuar preenchendo manualmente. Não há processamento em lote nem varredura automática de bases.

## Referências primárias

- [ViaCEP — contrato e exemplos](https://viacep.com.br/)
- [CNPJ.ws — API pública de consulta](https://docs.cnpj.ws/referencia-de-api/api-publica/consultando-cnpj)
- [BrasilAPI — documentação](https://brasilapi.com.br/docs)

Os testes locais utilizam dados sintéticos e transportes simulados, incluindo um exercício com a classe real de consultas do Multiempresa. Isso não garante disponibilidade contínua dos serviços externos.
